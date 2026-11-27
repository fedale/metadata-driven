<?php

declare(strict_types=1);

namespace App\Module\Package\Application;

use App\Module\Metadata\Contract\Dto\KeyType;
use App\Module\Metadata\Contract\Dto\TextDto;
use App\Module\Metadata\Contract\Dto\TranslationKey;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Contract\MetadataTranslationsInterface;
use App\Module\Package\Application\Source\OpenedPackage;
use App\Module\Package\Application\Upgrade\SeedFingerprints;
use App\Module\Package\Application\Upgrade\Snapshot;
use App\Module\Package\Domain\Exception\InstallRefused;
use App\Module\Package\Domain\Model\ItemKind;
use App\Module\Package\Domain\Model\PackageInstallation;
use App\Module\Package\Domain\Model\PackageItem;
use App\Module\Package\Domain\Repository\PackageInstallationRepository;
use App\Module\Package\Domain\Repository\PackageItemRepository;
use App\Module\Package\Domain\Repository\PersistenceReset;
use App\Module\Workflow\Contract\Exception\InvalidWorkflowSource;
use App\Module\Workflow\Contract\PackagedWorkflowsInterface;
use App\Shared\Message\Message;
use App\Shared\Message\MessageList;
use App\Shared\Transaction\TransactionRunner;
use Fedale\PackageFormat\Document;
use Fedale\PackageFormat\DocumentKind;
use Fedale\PackageFormat\FieldTypes\FieldTypeCatalog;
use Fedale\PackageFormat\FieldTypes\ReferenceKind;
use Fedale\PackageFormat\Package;
use Fedale\PackageFormat\PackageChecker;
use Fedale\PackageFormat\SeedRecords;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

/**
 * Installs a package: its objects, fields, views, forms and translations, through the same
 * metadata API as the admin, so that every rule of the app applies. All of it runs in one
 * transaction (PostgreSQL DDL is transactional): a step the app refuses rolls everything back.
 * A dry run goes all the way, then rolls back.
 *
 * The order follows what each step needs to exist before it: objects; fields, those naming
 * another field after it (a cascade, a junction); conditions, record label, search and unique
 * sets; the {t:name} texts, before the layouts placing them; views and forms; the other
 * translations, whose keys name the items; the workflows, as drafts (an admin activates them, and
 * their steps then act with that admin's permissions); the records of the seeds (SeedWriter).
 */
final class PackageInstaller
{
    public function __construct(
        private readonly PackageChecker $checker,
        private readonly FieldTypeCatalog $fieldTypes,
        private readonly MetadataManagerInterface $metadata,
        private readonly MetadataRegistryInterface $registry,
        private readonly MetadataTranslationsInterface $translations,
        private readonly PackageInstallationRepository $installations,
        private readonly PackageItemRepository $items,
        private readonly TransactionRunner $transactions,
        private readonly SeedWriter $seeds,
        private readonly SeedRecords $seedRecords,
        private readonly PackageExporter $exporter,
        private readonly PersistenceReset $persistence,
        private readonly SeedFingerprints $fingerprints,
        private readonly RequiredPackages $required,
        private readonly PackagedWorkflowsInterface $workflows,
    ) {
    }

    /**
     * A package requiring others (spec.requires) is checked against them as installed: each
     * must be installed in a version its constraint allows, and its references resolve to their
     * items. A missing one is refused, named: installAll() installs a package along with them.
     *
     * @param string $source where the package was read from, recorded with the installation
     *
     * @throws InstallRefused the package is invalid, already installed, collides with local
     *                        items, a package it requires is missing or in a version it does
     *                        not allow, or a step was refused (nothing is then left behind)
     */
    public function install(Package $package, string $source, bool $dryRun = false): InstallReport
    {
        return $this->installAll([new OpenedPackage($package, $source, null)], $dryRun)[0];
    }

    /**
     * Installs packages in the order given, each after the packages it requires: a package
     * and the requirements it comes with (PackageLocator::openWithRequirements()). All of them
     * in one transaction, checked one after the other inside it, so that each is checked
     * against those installed before it: a refusal of any leaves none installed, and a dry run
     * goes through them all.
     *
     * @param non-empty-list<OpenedPackage> $packages
     *
     * @return non-empty-list<InstallReport> in the same order
     *
     * @throws InstallRefused naming the package refused when there are several
     */
    public function installAll(array $packages, bool $dryRun = false): array
    {
        try {
            return $this->transactions->run(function () use ($packages, $dryRun): array {
                $reports = [];
                foreach ($packages as $opened) {
                    try {
                        $reports[] = $this->installOne($opened->package, $opened->source, $dryRun);
                    } catch (InstallRefused $e) {
                        throw \count($packages) > 1 ? $e->of($opened->package->name(), $opened->package->version()) : $e;
                    }
                }
                if ($dryRun) {
                    throw new DryRunCompleted($reports);
                }

                return $reports;
            });
        } catch (DryRunCompleted $completed) {
            $this->persistence->reset();

            return $completed->reports;
        } catch (\Throwable $e) {
            // Rolled back too: the entities it created must not outlive it.
            $this->persistence->reset();

            throw $e;
        }
    }

    /**
     * Checks a package against this instance as it is now, then installs it.
     *
     * @throws InstallRefused
     */
    private function installOne(Package $package, string $source, bool $dryRun): InstallReport
    {
        $required = $this->required->installed($package);
        $problems = $this->checker->check($package, $required);
        if ([] !== $problems) {
            throw InstallRefused::invalid(array_map(strval(...), $problems));
        }
        $installed = $this->installations->findByName($package->name());
        if (null !== $installed) {
            throw InstallRefused::alreadyInstalled($installed->getName(), $installed->getVersion());
        }
        $requires = array_map(static fn (Package $requirement): string => $requirement->name(), $required);
        $requiredItems = $this->required->items($requires);
        $names = new PackageNames($package, $requiredItems);
        $collisions = $this->collisions($package, $names);
        if ([] !== $collisions) {
            throw InstallRefused::collisions($collisions);
        }

        return $this->apply($package, $names, $source, $dryRun, $required, $requires, $requiredItems);
    }

    /**
     * Local objects and forms named like the package's: the package does not own them, and
     * an install never touches what it does not own.
     *
     * @return list<Message>
     */
    private function collisions(Package $package, PackageNames $names): array
    {
        $collisions = [];
        $archived = $this->registry->archivedObjects();
        foreach ($package->documentsOf(DocumentKind::Object) as $object) {
            $name = $names->object($object->id);
            if ($this->registry->hasObject($name) || isset($archived[$name])) {
                $collisions[] = InstallRefused::located($object->path, 'spec.name', isset($archived[$name])
                    ? new Message('package.install.archived_object_taken', 'This instance already has an object "%name%" (archived).', ['name' => $name])
                    : new Message('package.install.object_taken', 'This instance already has an object "%name%".', ['name' => $name]));
            }
        }
        $forms = [];
        foreach ($package->documentsOf(DocumentKind::Form) as $form) {
            $name = self::string($form->spec['name'] ?? null);
            $forms[] = $name;
            if ($this->registry->hasForm($name)) {
                $collisions[] = InstallRefused::located($form->path, 'spec.name', new Message('package.install.form_taken', 'This instance already has a form "%name%".', ['name' => $name]));
            }
        }

        // A translation may only name what the package brings: the labels of its own objects
        // and forms, and texts this instance does not have yet. Otherwise a package could
        // rewrite the labels and texts of local items.
        $prefixes = [];
        foreach ($package->documentsOf(DocumentKind::Object) as $object) {
            $prefixes = [...$prefixes, ...TranslationKey::objectPrefixes($names->object($object->id))];
        }
        foreach ($forms as $form) {
            $prefixes = [...$prefixes, TranslationKey::form($form), 'section.'.$form, 'description.'.$form, 'service.'.$form];
        }
        $localWorkflows = array_flip(array_map(static fn ($workflow): string => $workflow->name, $this->workflows->all()));
        foreach ($package->documentsOf(DocumentKind::Workflow) as $workflow) {
            $name = self::string($workflow->spec['name'] ?? null);
            if (isset($localWorkflows[$name])) {
                $collisions[] = InstallRefused::located($workflow->path, 'spec.name', new Message('package.install.workflow_taken', 'This instance already has a workflow "%name%".', ['name' => $name]));
            }
        }

        $localTexts = array_flip(array_map(static fn (TextDto $text): string => $text->key(), $this->translations->texts()));
        foreach ($package->documentsOf(DocumentKind::Translations) as $document) {
            foreach (array_keys(self::map($document->spec['entries'] ?? [])) as $key) {
                $key = (string) $key;
                if (TranslationKey::isText($key)) {
                    if (isset($localTexts[$key])) {
                        $collisions[] = InstallRefused::located($document->path, 'spec.entries.'.$key, new Message('package.install.text_taken', 'This instance already has the text "%name%".', ['name' => substr($key, \strlen(TranslationKey::TEXT))]));
                    }
                } elseif ([] === array_filter($prefixes, static fn (string $prefix): bool => $key === $prefix || str_starts_with($key, $prefix.'.'))) {
                    $collisions[] = InstallRefused::located($document->path, 'spec.entries.'.$key, new Message('package.install.foreign_translation', 'Names no object or form of the package.'));
                }
            }
        }

        return $collisions;
    }

    /**
     * @param list<Package> $required the packages it requires, as installed
     * @param list<string>  $requires their names
     */
    private function apply(Package $package, PackageNames $names, string $source, bool $dryRun, array $required, array $requires, RequiredItems $requiredItems): InstallReport
    {
        $items = [];
        $objects = $package->documentsOf(DocumentKind::Object);
        foreach ($objects as $object) {
            $localId = $this->step($object->path, 'spec', fn (): string => $this->metadata->createObject(
                $names->object($object->id),
                self::string($object->spec['label'] ?? null),
                self::string($object->spec['plural'] ?? null),
                \is_string($object->spec['icon'] ?? null) ? $object->spec['icon'] : null,
                self::keyType($object->spec['key'] ?? null),
            ));
            $items[] = $this->item($package, ItemKind::Object, $object->id, $localId);
        }

        $fields = $this->fieldsInOrder($objects, $names);
        foreach ($fields as [$object, $position, $field]) {
            $id = self::string($field['id']);
            $type = self::string($field['type']);
            $localId = $this->step($object->path, \sprintf('spec.fields.%d', $position), fn (): string => $this->metadata->createField(
                $names->object($object->id),
                self::string($field['name']),
                $type,
                self::string($field['label']),
                true === ($field['required'] ?? false),
                true === ($field['unique'] ?? false),
                $this->settings($type, self::map($field['settings'] ?? []), $names),
            ));
            $items[] = $this->item($package, ItemKind::Field, $id, $localId);
        }

        foreach ($objects as $object) {
            $this->configureObject($object, $names);
        }

        $enabled = $this->translations->locales();
        $default = $this->translations->defaultLocale();
        $translations = $package->documentsOf(DocumentKind::Translations);
        $skipped = [];
        foreach ($translations as $document) {
            $locale = self::string($document->spec['locale'] ?? null);
            if (!\in_array($locale, $enabled, true)) {
                $skipped[] = $locale;
            }
        }
        // The texts first, in the default language first: a layout may only place a text defined
        // in it, and a text is translated once it exists.
        usort($translations, static fn (Document $a, Document $b): int => ($b->spec['locale'] === $default) <=> ($a->spec['locale'] === $default));
        $count = $this->translate($translations, $enabled, static fn (string $key): bool => TranslationKey::isText($key));

        foreach ($package->documentsOf(DocumentKind::View) as $view) {
            $localId = $this->step($view->path, 'spec', fn (): string => $this->metadata->saveView(
                $names->object(self::string($view->spec['object'] ?? null)),
                ViewKind::from(self::string($view->spec['kind'] ?? null)),
                self::string($view->spec['name'] ?? null),
                self::string($view->spec['source'] ?? null),
                true === ($view->spec['default'] ?? false),
            ));
            $items[] = $this->item($package, ItemKind::View, $view->id, $localId);
        }
        $forms = [];
        foreach ($package->documentsOf(DocumentKind::Form) as $form) {
            $name = self::string($form->spec['name'] ?? null);
            $definition = self::withNames(self::map($form->spec['definition'] ?? []), $names);
            $localId = $this->step($form->path, 'spec.definition', fn (): string => $this->metadata->saveForm($name, Yaml::dump($definition, 10, 2)));
            $items[] = $this->item($package, ItemKind::Form, $form->id, $localId);
            $forms[] = $name;
        }

        $count += $this->translate($translations, $enabled, static fn (string $key): bool => !TranslationKey::isText($key));

        // The workflows, as drafts: nothing of theirs runs until an admin activates them.
        $workflows = [];
        foreach ($package->documentsOf(DocumentKind::Workflow) as $workflow) {
            $name = self::string($workflow->spec['name'] ?? null);
            $definition = WorkflowReferences::map(self::map($workflow->spec['definition'] ?? []), $names->object(...), $names->workflow(...));
            try {
                $localId = $this->workflows->create($name, \is_string($workflow->spec['description'] ?? null) ? $workflow->spec['description'] : null, $definition);
            } catch (InvalidWorkflowSource $e) {
                throw InstallRefused::at($workflow->path, 'spec.definition', new MessageList($e->located(), ' '));
            }
            $items[] = $this->item($package, ItemKind::Workflow, $workflow->id, $localId);
            $workflows[] = $name;
        }

        // The records last: their tables, fields and forms all exist by now.
        $records = $this->seeds->write($package, $names, null, $this->required->records($package, $required, $requiredItems));

        // The items first: the snapshot reads back what the package owns now.
        $this->items->add($items);
        $snapshot = Snapshot::of($this->exporter->ownedState($package->name(), $requires), $package, $this->seedRecords, $this->fingerprints->of($package));
        $this->installations->save(new PackageInstallation(Uuid::v7(), $package->name(), $package->version(), $source, $snapshot, new \DateTimeImmutable()));

        return new InstallReport(
            $package->name(),
            $package->version(),
            $source,
            $dryRun,
            array_map(fn (Document $object): string => $names->object($object->id), $objects),
            \count($fields),
            \count($package->documentsOf(DocumentKind::View)),
            $forms,
            $count,
            $records,
            array_values(array_unique($skipped)),
            $workflows,
        );
    }

    /**
     * Every field of every object, a field naming another one (dependsOn, dependsVia,
     * throughSource, throughTarget) after it. The checker has made sure every reference
     * resolves; a cycle would be refused by the app anyway, so it is reported here as such.
     * A field of a package it requires exists already: nothing to wait for.
     *
     * @param list<Document> $objects
     *
     * @return list<array{Document, int, array<mixed>}>
     */
    private function fieldsInOrder(array $objects, PackageNames $names): array
    {
        $pending = [];
        foreach ($objects as $object) {
            foreach (self::map($object->spec['fields'] ?? []) as $position => $field) {
                $field = self::map($field);
                $dependencies = [];
                foreach ($this->fieldTypes->references(self::string($field['type'] ?? null), self::keyed(self::map($field['settings'] ?? []))) as $reference) {
                    if (ReferenceKind::Field === $reference->kind && $names->isOwnField($reference->id)) {
                        $dependencies[] = $reference->id;
                    }
                }
                $pending[self::string($field['id'] ?? null)] = [$object, (int) $position, $field, $dependencies];
            }
        }

        $ordered = [];
        $done = [];
        while ([] !== $pending) {
            $ready = array_filter($pending, static fn (array $entry): bool => [] === array_diff($entry[3], array_keys($done)));
            if ([] === $ready) {
                [$object, $position] = reset($pending);
                throw InstallRefused::at($object->path, \sprintf('spec.fields.%d', $position), new Message('package.install.field_cycle', 'Its settings and those of other fields name each other in a cycle.'));
            }
            foreach ($ready as $id => [$object, $position, $field]) {
                $ordered[] = [$object, $position, $field];
                $done[$id] = true;
                unset($pending[$id]);
            }
        }

        return $ordered;
    }

    private function configureObject(Document $object, PackageNames $names): void
    {
        $name = $names->object($object->id);
        foreach (self::map($object->spec['fields'] ?? []) as $position => $field) {
            $field = self::map($field);
            $condition = $field['condition'] ?? null;
            if (\is_array($condition)) {
                $this->step($object->path, \sprintf('spec.fields.%d.condition', $position), function () use ($name, $field, $condition, $names): void {
                    $this->metadata->configureFieldCondition($name, self::string($field['name'] ?? null), $names->field(self::string($condition['field'] ?? null)), array_values(array_filter(self::map($condition['values'] ?? []), is_string(...))));
                });
            }
        }
        if (\is_string($object->spec['label_field'] ?? null)) {
            $label = $names->field($object->spec['label_field']);
            $this->step($object->path, 'spec.label_field', fn () => $this->metadata->configureLabel($name, $label));
        }
        $search = $object->spec['search'] ?? null;
        if (\is_array($search)) {
            $fields = \is_array($search['fields'] ?? null) ? array_map(static fn (mixed $id): string => $names->field(self::string($id)), $search['fields']) : null;
            $this->step($object->path, 'spec.search', fn () => $this->metadata->configureSearch($name, \is_string($search['handler'] ?? null) ? $search['handler'] : null, null === $fields ? null : array_values($fields)));
        }
        foreach (self::map($object->spec['unique_together'] ?? []) as $i => $set) {
            $fields = array_values(array_map(static fn (mixed $id): string => $names->field(self::string($id)), self::map($set)));
            $this->step($object->path, \sprintf('spec.unique_together.%d', $i), fn () => $this->metadata->addUniqueTogether($name, $fields));
        }
    }

    /**
     * @param list<Document>         $translations
     * @param list<string>           $enabled
     * @param \Closure(string): bool $which        the keys translated in this pass
     */
    private function translate(array $translations, array $enabled, \Closure $which): int
    {
        $count = 0;
        foreach ($translations as $document) {
            $locale = self::string($document->spec['locale'] ?? null);
            if (!\in_array($locale, $enabled, true)) {
                continue;
            }
            foreach (self::map($document->spec['entries'] ?? []) as $key => $text) {
                $key = (string) $key;
                if ($which($key) && \is_string($text)) {
                    $this->step($document->path, 'spec.entries.'.$key, fn () => $this->metadata->translate($locale, $key, $text));
                    ++$count;
                }
            }
        }

        return $count;
    }

    /**
     * A field's settings as the app takes them: the ids naming objects and fields turned into
     * their names.
     *
     * @param array<mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function settings(string $type, array $settings, PackageNames $names): array
    {
        $resolved = self::keyed($settings);
        foreach ($this->fieldTypes->references($type, $resolved) as $reference) {
            $resolved[$reference->setting] = ReferenceKind::Object === $reference->kind
                ? $names->object($reference->id)
                : $names->field($reference->id);
        }

        return $resolved;
    }

    /**
     * A node of a form's definition, its object and via turned into names.
     *
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function withNames(array $node, PackageNames $names): array
    {
        $node['object'] = $names->object(self::string($node['object'] ?? null));
        if (\is_string($node['via'] ?? null)) {
            $node['via'] = $names->field($node['via']);
        }
        if (\is_array($node['sections'] ?? null)) {
            $node['sections'] = array_map(
                static fn (mixed $section): mixed => \is_array($section) ? self::withNames($section, $names) : $section,
                $node['sections'],
            );
        }

        return $node;
    }

    /**
     * Runs one step; a refusal of the app is reported on the document and the place in it.
     *
     * @template T
     *
     * @param callable(): T $step
     *
     * @return T
     */
    private function step(string $path, string $at, callable $step): mixed
    {
        try {
            return $step();
        } catch (MetadataException $e) {
            throw InstallRefused::at($path, $at, $e->text());
        }
    }

    private function item(Package $package, ItemKind $kind, string $itemId, string $localId): PackageItem
    {
        return new PackageItem(Uuid::v7(), $package->name(), $kind, $itemId, $localId);
    }

    /**
     * A YAML map, keyed by strings: a key written as a number is a name there too.
     *
     * @param array<mixed> $map
     *
     * @return array<string, mixed>
     */
    private static function keyed(array $map): array
    {
        $keyed = [];
        foreach ($map as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    /**
     * @return array<mixed>
     */
    private static function map(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    private static function string(mixed $value): string
    {
        // The checker has validated every document against its schema: a miss is a bug.
        return \is_string($value) ? $value : throw new \LogicException('A value the schema requires is missing.');
    }

    /**
     * The key an object's spec says; left out (or before 2.4), a UUID one. The checker has
     * refused any other value.
     */
    public static function keyType(mixed $key): KeyType
    {
        return \is_string($key) ? KeyType::tryFrom($key) ?? KeyType::Uuid : KeyType::Uuid;
    }
}
