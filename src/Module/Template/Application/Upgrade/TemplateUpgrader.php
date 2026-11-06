<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

use App\Module\Metadata\Contract\Dto\TranslationKey;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Contract\MetadataTranslationsInterface;
use App\Module\Template\Application\TemplateExporter;
use App\Module\Template\Domain\Exception\UpgradeRefused;
use App\Module\Template\Domain\Model\ItemKind;
use App\Module\Template\Domain\Model\TemplateItem;
use App\Module\Template\Domain\Repository\PersistenceReset;
use App\Module\Template\Domain\Repository\TemplateInstallationRepository;
use App\Module\Template\Domain\Repository\TemplateItemRepository;
use App\Shared\Transaction\TransactionRunner;
use App\TemplateFormat\FieldTypes\FieldTypeCatalog;
use App\TemplateFormat\FieldTypes\ReferenceKind;
use App\TemplateFormat\Package;
use App\TemplateFormat\PackageChecker;
use App\TemplateFormat\SeedRecords;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

/**
 * Upgrades an installed template to a new version: only what the new version changed is
 * applied, what the instance changed is kept, and an item both changed is a conflict, stopped
 * unless a strategy says who wins (see UpgradePlan). All in one transaction, through the same
 * metadata API as the admin; a dry run goes all the way, then rolls back.
 *
 * The order: renames (an object or a field keeps its table, column and every reference); new
 * objects and fields; changed fields and objects; texts; views and forms; translations; last,
 * what the new version removed: views and forms deleted, fields and objects archived (their
 * data kept).
 *
 * Changes the app cannot make to an existing item (a field's type, whether it is required or
 * unique, an object's icon, a field moved to another object) refuse the upgrade.
 */
final class TemplateUpgrader
{
    public function __construct(
        private readonly PackageChecker $checker,
        private readonly ItemStates $states,
        private readonly TemplateExporter $exporter,
        private readonly FieldTypeCatalog $fieldTypes,
        private readonly MetadataManagerInterface $metadata,
        private readonly MetadataRegistryInterface $registry,
        private readonly MetadataTranslationsInterface $translations,
        private readonly TemplateInstallationRepository $installations,
        private readonly TemplateItemRepository $items,
        private readonly TransactionRunner $transactions,
        private readonly SeedRecords $seedRecords,
        private readonly PersistenceReset $persistence,
    ) {
    }

    /**
     * @throws UpgradeRefused nothing was changed
     */
    public function upgrade(Package $theirs, string $source, ?Strategy $strategy = null, bool $dryRun = false): UpgradeReport
    {
        $problems = $this->checker->check($theirs);
        if ([] !== $problems) {
            throw new UpgradeRefused(array_map(strval(...), $problems));
        }
        $requires = \is_array($theirs->manifest->spec['requires'] ?? null) ? $theirs->manifest->spec['requires'] : [];
        foreach (array_keys($requires) as $required) {
            if ('format' !== $required) {
                throw UpgradeRefused::at('template.yaml', 'spec.requires.'.$required, 'A template requiring another one cannot be upgraded yet.');
            }
        }
        $installation = $this->installations->findByName($theirs->name()) ?? throw UpgradeRefused::notInstalled($theirs->name());
        if (!version_compare($theirs->version(), $installation->getVersion(), '>')) {
            throw UpgradeRefused::notNewer($theirs->name(), $installation->getVersion(), $theirs->version());
        }

        $mine = $this->exporter->ownedState($theirs->name());
        $plan = UpgradePlan::compute(
            $this->states->of(Snapshot::toPackage($installation->getSnapshot())),
            $this->states->of($theirs),
            $this->states->of($mine),
        );
        $conflicts = $plan->conflicts();
        if ([] !== $conflicts && null === $strategy) {
            throw UpgradeRefused::conflicts(array_map(static fn (Change $change): string => $change->describe().', changed here too', $conflicts));
        }
        $changes = $plan->toApply($strategy);
        $refusals = [...$this->unsupported($changes), ...$this->collisions($changes)];
        if ([] !== $refusals) {
            throw new UpgradeRefused($refusals);
        }

        $report = new UpgradeReport(
            $theirs->name(),
            $installation->getVersion(),
            $theirs->version(),
            $dryRun,
            array_map(static fn (Change $change): string => $change->describe(), $changes),
            Strategy::Keep === $strategy ? array_map(static fn (Change $change): string => $change->describe(), $conflicts) : [],
        );
        try {
            return $this->transactions->run(function () use ($theirs, $mine, $changes, $installation, $source, $report, $dryRun): UpgradeReport {
                $this->apply($theirs, $mine, $changes);
                $snapshot = Snapshot::of($this->exporter->ownedState($theirs->name()), $theirs, $this->seedRecords);
                // A dry run leaves the installation alone: the entity would keep the new version
                // in memory after the rollback.
                if ($dryRun) {
                    throw new UpgradeRolledBack($report);
                }
                $installation->upgradeTo($theirs->version(), $source, $snapshot);
                $this->installations->save($installation);

                return $report;
            });
        } catch (UpgradeRolledBack $rolledBack) {
            $this->persistence->reset();

            return $rolledBack->report;
        } catch (\Throwable $e) {
            // Rolled back too: the entities it changed must not outlive it.
            $this->persistence->reset();

            throw $e;
        }
    }

    /**
     * @param list<Change> $changes
     */
    private function apply(Package $theirs, Package $mine, array $changes): void
    {
        $names = new CurrentNames($this->fieldTypes);
        $names->learn($mine);
        $byKind = [];
        foreach ($changes as $change) {
            $byKind[$change->state()->kind][$change->action->value][] = $change;
        }
        $items = [];

        // Renames first: everything after names items as the new version does.
        foreach ($byKind['object']['change'] ?? [] as $change) {
            $new = self::value($change->theirs);
            if (null !== $change->mine && $new['name'] !== self::value($change->mine)['name']) {
                $this->step($change, fn () => $this->metadata->renameObject($names->object($change->state()->id), self::string($new['name'])));
                $names->renameObject($change->state()->id, self::string($new['name']));
            }
        }
        foreach ($byKind['field']['change'] ?? [] as $change) {
            $new = self::value($change->theirs);
            if (null !== $change->mine && $new['name'] !== self::value($change->mine)['name']) {
                $id = $change->state()->id;
                $this->step($change, fn () => $this->metadata->renameField($names->objectOfField($id), $names->field($id), self::string($new['name'])));
                $names->renameField($id, self::string($new['object']), self::string($new['name']));
            }
        }

        foreach ($byKind['object']['add'] ?? [] as $change) {
            $new = self::value($change->theirs);
            $localId = $this->step($change, fn (): string => $this->metadata->createObject(self::string($new['name']), self::string($new['label']), self::string($new['plural']), \is_string($new['icon']) ? $new['icon'] : null));
            $names->renameObject($change->state()->id, self::string($new['name']));
            $items[] = $this->item($theirs, ItemKind::Object, $change->state()->id, $localId);
        }
        // New fields: their names are known first, for the settings naming one another.
        foreach ($byKind['field']['add'] ?? [] as $change) {
            $new = self::value($change->theirs);
            $names->renameField($change->state()->id, self::string($new['object']), self::string($new['name']));
        }
        foreach ($this->inOrder($byKind['field']['add'] ?? []) as $change) {
            $new = self::value($change->theirs);
            $localId = $this->step($change, fn (): string => $this->metadata->createField(
                $names->object(self::string($new['object'])),
                self::string($new['name']),
                self::string($new['type']),
                self::string($new['label']),
                true === $new['required'],
                true === $new['unique'],
                $names->settings(self::string($new['type']), self::keyed($new['settings'])),
            ));
            $items[] = $this->item($theirs, ItemKind::Field, $change->state()->id, $localId);
        }

        foreach ([...$byKind['field']['change'] ?? [], ...$byKind['field']['add'] ?? []] as $change) {
            $this->changeField($change, $names);
        }
        foreach ([...$byKind['object']['change'] ?? [], ...$byKind['object']['add'] ?? []] as $change) {
            $this->changeObject($change, $names);
        }

        // The texts before the layouts placing them; in the default language first.
        $translations = [...$byKind['translation']['add'] ?? [], ...$byKind['translation']['change'] ?? []];
        $default = $this->translations->defaultLocale();
        usort($translations, static fn (Change $a, Change $b): int => ($b->state()->extra['locale'] === $default) <=> ($a->state()->extra['locale'] === $default));
        $enabled = $this->translations->locales();
        $this->translate(array_filter($translations, static fn (Change $change): bool => TranslationKey::isText(self::string($change->state()->extra['key']))), $names, $enabled);

        foreach ([...$byKind['view']['change'] ?? [], ...$byKind['view']['add'] ?? []] as $change) {
            $items = [...$items, ...$this->saveView($change, $theirs, $names)];
        }
        foreach ([...$byKind['form']['change'] ?? [], ...$byKind['form']['add'] ?? []] as $change) {
            $items = [...$items, ...$this->saveForm($change, $theirs, $names)];
        }
        $this->translate(array_filter($translations, static fn (Change $change): bool => !TranslationKey::isText(self::string($change->state()->extra['key']))), $names, $enabled);

        // What the new version removed, last: nothing of it may still be in use.
        foreach ($byKind['translation']['remove'] ?? [] as $change) {
            $locale = self::string($change->state()->extra['locale']);
            if (\in_array($locale, $enabled, true)) {
                $key = $names->translationKey(substr($change->state()->id, \strlen($locale) + 1));
                $this->step($change, fn () => $this->metadata->translate($locale, $key, null));
            }
        }
        $gone = [];
        foreach ($byKind['view']['remove'] ?? [] as $change) {
            $old = self::value($change->mine);
            $this->step($change, fn () => $this->metadata->deleteView($names->object(self::string($old['object'])), ViewKind::from(self::string($old['kind'])), self::string($old['name'])));
            $gone[] = $this->localId(ItemKind::View, $change->state()->id, $theirs->name());
        }
        foreach ($byKind['form']['remove'] ?? [] as $change) {
            $this->step($change, fn () => $this->metadata->deleteForm(self::string(self::value($change->mine)['name'])));
            $gone[] = $this->localId(ItemKind::Form, $change->state()->id, $theirs->name());
        }
        foreach ($byKind['field']['remove'] ?? [] as $change) {
            $id = $change->state()->id;
            $this->step($change, fn () => $this->metadata->archiveField($names->objectOfField($id), $names->field($id)));
        }
        foreach ($byKind['object']['remove'] ?? [] as $change) {
            $this->step($change, fn () => $this->metadata->archiveObject($names->object($change->state()->id)));
        }

        $this->items->remove($theirs->name(), array_values(array_filter($gone, is_string(...))));
        $this->items->add($items);
    }

    private function changeField(Change $change, CurrentNames $names): void
    {
        $new = self::value($change->theirs);
        $old = null === $change->mine ? null : self::value($change->mine);
        $id = $change->state()->id;
        if (null === $old) {
            // Just created: only its condition is left to set.
            $old = ['label' => $new['label'], 'settings' => $new['settings'], 'condition' => null];
        }
        $object = fn (): string => $names->objectOfField($id);
        if ($new['label'] !== $old['label']) {
            $this->step($change, fn () => $this->metadata->relabelField($object(), $names->field($id), self::string($new['label'])));
        }
        if ($new['settings'] !== $old['settings']) {
            // Every setting that differs, one the new version left out back to its default.
            $type = self::string($new['type']);
            $wanted = self::keyed($new['settings']) + $this->fieldTypes->defaults($type);
            $current = self::keyed($old['settings']) + $this->fieldTypes->defaults($type);
            $changed = array_filter($wanted, static fn (mixed $value, string $key): bool => !\array_key_exists($key, $current) || $current[$key] !== $value, \ARRAY_FILTER_USE_BOTH);
            $this->step($change, fn () => $this->metadata->configureFieldSettings($object(), $names->field($id), $names->settings($type, $changed)));
        }
        if ($new['condition'] !== $old['condition']) {
            $condition = \is_array($new['condition']) ? $new['condition'] : null;
            $this->step($change, fn () => $this->metadata->configureFieldCondition(
                $object(),
                $names->field($id),
                null === $condition ? null : $names->field(self::string($condition['field'] ?? null)),
                null === $condition ? [] : array_values(array_filter(self::map($condition['values'] ?? []), is_string(...))),
            ));
        }
    }

    private function changeObject(Change $change, CurrentNames $names): void
    {
        $new = self::value($change->theirs);
        $old = null === $change->mine
            ? ['label' => $new['label'], 'plural' => $new['plural'], 'label_field' => null, 'search' => null, 'unique_together' => []]
            : self::value($change->mine);
        $name = $names->object($change->state()->id);
        if ($new['label'] !== $old['label'] || $new['plural'] !== $old['plural']) {
            $this->step($change, fn () => $this->metadata->relabelObject($name, self::string($new['label']), self::string($new['plural'])));
        }
        if ($new['label_field'] !== $old['label_field']) {
            $this->step($change, fn () => $this->metadata->configureLabel($name, \is_string($new['label_field']) ? $names->field($new['label_field']) : null));
        }
        if ($new['search'] !== $old['search']) {
            $search = \is_array($new['search']) ? $new['search'] : ['handler' => null, 'fields' => null];
            $fields = \is_array($search['fields'] ?? null) ? array_values(array_map(static fn (mixed $id): string => $names->field(self::string($id)), $search['fields'])) : null;
            $this->step($change, fn () => $this->metadata->configureSearch($name, \is_string($search['handler'] ?? null) ? $search['handler'] : null, $fields));
        }
        $wanted = self::map($new['unique_together']);
        $current = self::map($old['unique_together']);
        foreach ($current as $set) {
            if (!\in_array($set, $wanted, true)) {
                $this->step($change, fn () => $this->metadata->removeUniqueTogether($name, array_values(array_map(static fn (mixed $id): string => $names->field(self::string($id)), self::map($set)))));
            }
        }
        foreach ($wanted as $set) {
            if (!\in_array($set, $current, true)) {
                $this->step($change, fn () => $this->metadata->addUniqueTogether($name, array_values(array_map(static fn (mixed $id): string => $names->field(self::string($id)), self::map($set)))));
            }
        }
    }

    /**
     * @return list<TemplateItem>
     */
    private function saveView(Change $change, Package $theirs, CurrentNames $names): array
    {
        $new = self::value($change->theirs);
        $old = null === $change->mine ? null : self::value($change->mine);
        $items = [];
        // Another name, kind or object is another view: the old one goes.
        if (null !== $old && [$old['object'], $old['kind'], $old['name']] !== [$new['object'], $new['kind'], $new['name']]) {
            $this->step($change, fn () => $this->metadata->deleteView($names->object(self::string($old['object'])), ViewKind::from(self::string($old['kind'])), self::string($old['name'])));
            $this->forget($theirs->name(), $this->localId(ItemKind::View, $change->state()->id, $theirs->name()));
            $old = null;
        }
        $localId = $this->step($change, fn (): string => $this->metadata->saveView(
            $names->object(self::string($new['object'])),
            ViewKind::from(self::string($new['kind'])),
            self::string($new['name']),
            self::string($new['template']),
            true === $new['default'],
        ));
        if (null === $old) {
            $items[] = $this->item($theirs, ItemKind::View, $change->state()->id, $localId);
        }

        return $items;
    }

    /**
     * @return list<TemplateItem>
     */
    private function saveForm(Change $change, Package $theirs, CurrentNames $names): array
    {
        $new = self::value($change->theirs);
        $old = null === $change->mine ? null : self::value($change->mine);
        $name = self::string($new['name']);
        $definition = Yaml::dump($names->definition(self::map($new['definition'])), 10, 2);
        $localId = $this->step($change, fn (): string => $this->metadata->saveForm($name, $definition));
        if (null !== $old && $old['name'] !== $name) {
            $this->step($change, fn () => $this->metadata->deleteForm(self::string($old['name'])));
            $this->forget($theirs->name(), $this->localId(ItemKind::Form, $change->state()->id, $theirs->name()));
            $old = null;
        }

        return null === $old ? [$this->item($theirs, ItemKind::Form, $change->state()->id, $localId)] : [];
    }

    /**
     * @param array<Change> $changes
     * @param list<string>  $enabled
     */
    private function translate(array $changes, CurrentNames $names, array $enabled): void
    {
        foreach ($changes as $change) {
            $state = $change->state();
            $locale = self::string($state->extra['locale']);
            if (!\in_array($locale, $enabled, true) || !\is_string($state->value)) {
                continue;
            }
            $key = $names->translationKey(substr($state->id, \strlen($locale) + 1));
            $text = $state->value;
            $this->step($change, fn () => $this->metadata->translate($locale, $key, $text));
        }
    }

    /**
     * Changes the app cannot make to an item it already has.
     *
     * @param list<Change> $changes
     *
     * @return list<string>
     */
    private function unsupported(array $changes): array
    {
        $reasons = [];
        foreach ($changes as $change) {
            if (ChangeAction::Change !== $change->action || null === $change->mine) {
                continue;
            }
            $new = self::value($change->theirs);
            $old = self::value($change->mine);
            $kind = $change->state()->kind;
            $what = match (true) {
                'field' === $kind && $new['object'] !== $old['object'] => 'moved to another object',
                'field' === $kind && $new['type'] !== $old['type'] => \sprintf('its type changed from %s to %s', self::string($old['type']), self::string($new['type'])),
                'field' === $kind && $new['required'] !== $old['required'] => 'whether it is required changed',
                'field' === $kind && $new['unique'] !== $old['unique'] => 'whether it is unique changed',
                'object' === $kind && $new['icon'] !== $old['icon'] => 'its icon changed',
                default => null,
            };
            if (null !== $what) {
                $reasons[] = \sprintf('%s: %s %s: %s, which an upgrade cannot do yet: change it by hand first.', $change->state()->document->path, $kind, $change->state()->id, $what);
            }
        }

        return $reasons;
    }

    /**
     * New objects and forms named like local ones the template does not own.
     *
     * @param list<Change> $changes
     *
     * @return list<string>
     */
    private function collisions(array $changes): array
    {
        $reasons = [];
        foreach ($changes as $change) {
            $state = $change->state();
            $name = \is_array($state->value) && \is_string($state->value['name'] ?? null) ? $state->value['name'] : null;
            if (null === $name || ChangeAction::Remove === $change->action) {
                continue;
            }
            $renamed = ChangeAction::Change === $change->action && null !== $change->mine && self::value($change->mine)['name'] !== $name;
            if ('object' === $state->kind && (ChangeAction::Add === $change->action || $renamed) && ($this->registry->hasObject($name) || isset($this->registry->archivedObjects()[$name]))) {
                $reasons[] = \sprintf('%s: object %s: this instance already has an object "%s".', $state->document->path, $state->id, $name);
            }
            if ('form' === $state->kind && (ChangeAction::Add === $change->action || $renamed) && $this->registry->hasForm($name)) {
                $reasons[] = \sprintf('%s: form %s: this instance already has a form "%s".', $state->document->path, $state->id, $name);
            }
        }

        return $reasons;
    }

    /**
     * New fields, one naming another new field (a cascade, a junction) after it.
     *
     * @param list<Change> $changes
     *
     * @return list<Change>
     */
    private function inOrder(array $changes): array
    {
        $pending = [];
        foreach ($changes as $change) {
            $new = self::value($change->theirs);
            $dependencies = [];
            foreach ($this->fieldTypes->references(self::string($new['type']), self::keyed($new['settings'])) as $reference) {
                if (ReferenceKind::Field === $reference->kind) {
                    $dependencies[] = $reference->id;
                }
            }
            $pending[$change->state()->id] = [$change, $dependencies];
        }
        $ordered = [];
        while ([] !== $pending) {
            $ready = array_filter($pending, static fn (array $entry): bool => [] === array_intersect($entry[1], array_keys($pending)));
            if ([] === $ready) {
                [$change] = reset($pending);
                throw UpgradeRefused::at($change->state()->document->path, 'field '.$change->state()->id, 'Its settings and those of other new fields name each other in a cycle.');
            }
            foreach ($ready as $id => [$change]) {
                $ordered[] = $change;
                unset($pending[$id]);
            }
        }

        return $ordered;
    }

    /**
     * Runs one change; a refusal of the app is reported on its item.
     *
     * @template T
     *
     * @param callable(): T $step
     *
     * @return T
     */
    private function step(Change $change, callable $step): mixed
    {
        try {
            return $step();
        } catch (MetadataException $e) {
            throw UpgradeRefused::at($change->state()->document->path, $change->state()->kind.' '.$change->state()->id, $e->getMessage());
        }
    }

    private function item(Package $package, ItemKind $kind, string $itemId, string $localId): TemplateItem
    {
        return new TemplateItem(Uuid::v7(), $package->name(), $kind, $itemId, $localId);
    }

    private function forget(string $template, ?string $localId): void
    {
        if (null !== $localId) {
            $this->items->remove($template, [$localId]);
        }
    }

    private function localId(ItemKind $kind, string $itemId, string $template): ?string
    {
        foreach ($this->items->itemsOf($template) as $item) {
            if ($item->getKind() === $kind && $item->getItemId() === $itemId) {
                return $item->getLocalId();
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function value(?ItemState $state): array
    {
        $value = $state?->value;
        if (!\is_array($value)) {
            throw new \LogicException('An item of this kind holds a map.');
        }
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * A map of the template, keyed by strings: settings, as the catalog reads them.
     *
     * @return array<string, mixed>
     */
    private static function keyed(mixed $value): array
    {
        $keyed = [];
        foreach (\is_array($value) ? $value : [] as $key => $item) {
            $keyed[(string) $key] = $item;
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
        return \is_string($value) ? $value : throw new \LogicException('A value the checker requires is missing.');
    }
}
