<?php

declare(strict_types=1);

namespace App\Module\Template\Application;

use App\Module\Metadata\Contract\Dto\TextDto;
use App\Module\Metadata\Contract\Dto\TranslationKey;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Contract\MetadataTranslationsInterface;
use App\Module\Template\Application\Upgrade\SeedFingerprints;
use App\Module\Template\Application\Upgrade\Snapshot;
use App\Module\Template\Domain\Exception\InstallRefused;
use App\Module\Template\Domain\Model\ItemKind;
use App\Module\Template\Domain\Model\TemplateInstallation;
use App\Module\Template\Domain\Model\TemplateItem;
use App\Module\Template\Domain\Repository\PersistenceReset;
use App\Module\Template\Domain\Repository\TemplateInstallationRepository;
use App\Module\Template\Domain\Repository\TemplateItemRepository;
use App\Shared\Transaction\TransactionRunner;
use Fedale\TemplateFormat\Document;
use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\FieldTypes\FieldTypeCatalog;
use Fedale\TemplateFormat\FieldTypes\ReferenceKind;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\PackageChecker;
use Fedale\TemplateFormat\SeedRecords;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

/**
 * Installs a template: its objects, fields, views, forms and translations, through the same
 * metadata API as the admin, so that every rule of the app applies. All of it runs in one
 * transaction (PostgreSQL DDL is transactional): a step the app refuses rolls everything back.
 * A dry run goes all the way, then rolls back.
 *
 * The order follows what each step needs to exist before it: objects; fields, those naming
 * another field after it (a cascade, a junction); conditions, record label, search and unique
 * sets; the {t:name} texts, before the layouts placing them; views and forms; the other
 * translations, whose keys name the items; the records of the seeds (SeedWriter).
 */
final class TemplateInstaller
{
    public function __construct(
        private readonly PackageChecker $checker,
        private readonly FieldTypeCatalog $fieldTypes,
        private readonly MetadataManagerInterface $metadata,
        private readonly MetadataRegistryInterface $registry,
        private readonly MetadataTranslationsInterface $translations,
        private readonly TemplateInstallationRepository $installations,
        private readonly TemplateItemRepository $items,
        private readonly TransactionRunner $transactions,
        private readonly SeedWriter $seeds,
        private readonly SeedRecords $seedRecords,
        private readonly TemplateExporter $exporter,
        private readonly PersistenceReset $persistence,
        private readonly SeedFingerprints $fingerprints,
        private readonly RequiredTemplates $required,
    ) {
    }

    /**
     * @param string $source where the package was read from, recorded with the installation
     *
     * A template requiring others (spec.requires) is checked against them as installed: each
     * must be installed in a version its constraint allows, and its references resolve to their
     * items. A missing one is refused, named: installing it is up to the caller.
     *
     * @throws InstallRefused the template is invalid, already installed, collides with local
     *                        items, a template it requires is missing or in a version it does
     *                        not allow, or a step was refused (nothing is then left behind)
     */
    public function install(Package $package, string $source, bool $dryRun = false): InstallReport
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
        $names = new PackageNames($package, $this->required->items($requires));
        $collisions = $this->collisions($package, $names);
        if ([] !== $collisions) {
            throw InstallRefused::collisions($collisions);
        }

        try {
            return $this->transactions->run(function () use ($package, $names, $source, $dryRun, $required, $requires): InstallReport {
                $report = $this->apply($package, $names, $source, $dryRun, $required, $requires);
                if ($dryRun) {
                    throw new DryRunCompleted($report);
                }

                return $report;
            });
        } catch (DryRunCompleted $completed) {
            $this->persistence->reset();

            return $completed->report;
        } catch (\Throwable $e) {
            // Rolled back too: the entities it created must not outlive it.
            $this->persistence->reset();

            throw $e;
        }
    }

    /**
     * Local objects and forms named like the template's: the template does not own them, and
     * an install never touches what it does not own.
     *
     * @return list<string>
     */
    private function collisions(Package $package, PackageNames $names): array
    {
        $collisions = [];
        $archived = $this->registry->archivedObjects();
        foreach ($package->documentsOf(DocumentKind::Object) as $object) {
            $name = $names->object($object->id);
            if ($this->registry->hasObject($name) || isset($archived[$name])) {
                $collisions[] = \sprintf('%s: spec.name: This instance already has an object "%s"%s.', $object->path, $name, isset($archived[$name]) ? ' (archived)' : '');
            }
        }
        $forms = [];
        foreach ($package->documentsOf(DocumentKind::Form) as $form) {
            $name = self::string($form->spec['name'] ?? null);
            $forms[] = $name;
            if ($this->registry->hasForm($name)) {
                $collisions[] = \sprintf('%s: spec.name: This instance already has a form "%s".', $form->path, $name);
            }
        }

        // A translation may only name what the template brings: the labels of its own objects
        // and forms, and texts this instance does not have yet. Otherwise a template could
        // rewrite the labels and texts of local items.
        $prefixes = [];
        foreach ($package->documentsOf(DocumentKind::Object) as $object) {
            $prefixes = [...$prefixes, ...TranslationKey::objectPrefixes($names->object($object->id))];
        }
        foreach ($forms as $form) {
            $prefixes = [...$prefixes, TranslationKey::form($form), 'section.'.$form, 'description.'.$form, 'service.'.$form];
        }
        $localTexts = array_flip(array_map(static fn (TextDto $text): string => $text->key(), $this->translations->texts()));
        foreach ($package->documentsOf(DocumentKind::Translations) as $document) {
            foreach (array_keys(self::map($document->spec['entries'] ?? [])) as $key) {
                $key = (string) $key;
                if (TranslationKey::isText($key)) {
                    if (isset($localTexts[$key])) {
                        $collisions[] = \sprintf('%s: spec.entries.%s: This instance already has the text "%s".', $document->path, $key, substr($key, \strlen(TranslationKey::TEXT)));
                    }
                } elseif ([] === array_filter($prefixes, static fn (string $prefix): bool => $key === $prefix || str_starts_with($key, $prefix.'.'))) {
                    $collisions[] = \sprintf('%s: spec.entries.%s: Names no object or form of the template.', $document->path, $key);
                }
            }
        }

        return $collisions;
    }

    /**
     * @param list<Package> $required the templates it requires, as installed
     * @param list<string>  $requires their names
     */
    private function apply(Package $package, PackageNames $names, string $source, bool $dryRun, array $required, array $requires): InstallReport
    {
        $items = [];
        $objects = $package->documentsOf(DocumentKind::Object);
        foreach ($objects as $object) {
            $localId = $this->step($object->path, 'spec', fn (): string => $this->metadata->createObject(
                $names->object($object->id),
                self::string($object->spec['label'] ?? null),
                self::string($object->spec['plural'] ?? null),
                \is_string($object->spec['icon'] ?? null) ? $object->spec['icon'] : null,
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
                self::string($view->spec['template'] ?? null),
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

        // The records last: their tables, fields and forms all exist by now.
        $records = $this->seeds->write($package, $names, null, $this->requiredRecords($package, $required, $names));

        // The items first: the snapshot reads back what the template owns now.
        $this->items->add($items);
        $snapshot = Snapshot::of($this->exporter->ownedState($package->name(), $requires), $package, $this->seedRecords, $this->fingerprints->of($package));
        $this->installations->save(new TemplateInstallation(Uuid::v7(), $package->name(), $package->version(), $source, $snapshot, new \DateTimeImmutable()));

        return new InstallReport(
            $package->name(),
            $package->version(),
            $dryRun,
            array_map(fn (Document $object): string => $names->object($object->id), $objects),
            \count($fields),
            \count($package->documentsOf(DocumentKind::View)),
            $forms,
            $count,
            $records,
            array_values(array_unique($skipped)),
        );
    }

    /**
     * Every field of every object, a field naming another one (dependsOn, dependsVia,
     * throughSource, throughTarget) after it. The checker has made sure every reference
     * resolves; a cycle would be refused by the app anyway, so it is reported here as such.
     * A field of a template it requires exists already: nothing to wait for.
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
                throw InstallRefused::at($object->path, \sprintf('spec.fields.%d', $position), 'Its settings and those of other fields name each other in a cycle.');
            }
            foreach ($ready as $id => [$object, $position, $field]) {
                $ordered[] = [$object, $position, $field];
                $done[$id] = true;
                unset($pending[$id]);
            }
        }

        return $ordered;
    }

    /**
     * The records of the required templates' seeds that this template's seeds point at, found by
     * the value of their key as they are now (the user may have deleted or changed one since:
     * the seed writer then refuses the reference). Only the objects a seeded relation or
     * many-to-many field targets are read.
     *
     * @param list<Package> $required
     *
     * @return array<string, array<string, string>> object id => key => record id
     */
    private function requiredRecords(Package $package, array $required, PackageNames $names): array
    {
        $seeded = [];
        foreach ($package->documentsOf(DocumentKind::Seed) as $seed) {
            $seeded[self::string($seed->spec['object'] ?? null)] = true;
        }
        $targets = [];
        foreach ($package->documentsOf(DocumentKind::Object) as $object) {
            if (!isset($seeded[$object->id])) {
                continue;
            }
            foreach (self::map($object->spec['fields'] ?? []) as $field) {
                $field = self::map($field);
                $target = self::map($field['settings'] ?? [])['target'] ?? null;
                if (\in_array($field['type'] ?? null, ['relation', 'many_to_many'], true) && \is_string($target)) {
                    $targets[$target] = true;
                }
            }
        }

        $keys = [];
        foreach ($required as $requirement) {
            foreach ($requirement->documentsOf(DocumentKind::Seed) as $seed) {
                $object = self::string($seed->spec['object'] ?? null);
                if (!isset($targets[$object])) {
                    continue;
                }
                foreach ($this->fingerprints->current($names->object($object), $this->keyField($requirement, $object, self::string($seed->spec['key'] ?? null), $names)) as $value => $record) {
                    $keys[$object][$value] = $record->id;
                }
            }
        }

        return $keys;
    }

    /**
     * The name a required seed's key field has now: the seed names it as it was installed, and
     * the user may have renamed it since.
     */
    private function keyField(Package $requirement, string $objectId, string $key, PackageNames $names): string
    {
        foreach ($requirement->documentsOf(DocumentKind::Object) as $object) {
            if ($object->id !== $objectId) {
                continue;
            }
            foreach (self::map($object->spec['fields'] ?? []) as $field) {
                $field = self::map($field);
                if ($key === ($field['name'] ?? null) && \is_string($field['id'] ?? null)) {
                    return $names->field($field['id']);
                }
            }
        }

        return $key;
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
     * A node of a composite form's definition, its object and via turned into names.
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
            throw InstallRefused::at($path, $at, $e->getMessage());
        }
    }

    private function item(Package $package, ItemKind $kind, string $itemId, string $localId): TemplateItem
    {
        return new TemplateItem(Uuid::v7(), $package->name(), $kind, $itemId, $localId);
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
}
