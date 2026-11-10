<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

use App\Module\Metadata\Contract\Dto\TranslationKey;
use App\Module\Metadata\Contract\Dto\ViewKind;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Contract\MetadataTranslationsInterface;
use App\Module\Record\Contract\Dto\RecordInput;
use App\Module\Record\Contract\Exception\RecordDeleteRestricted;
use App\Module\Record\Contract\Exception\RecordValidationFailed;
use App\Module\Record\Contract\RecordFinderInterface;
use App\Module\Record\Contract\RecordManagerInterface;
use App\Module\Template\Application\PackageNames;
use App\Module\Template\Application\RequiredItems;
use App\Module\Template\Application\RequiredTemplates;
use App\Module\Template\Application\SeedWriter;
use App\Module\Template\Application\TemplateExporter;
use App\Module\Template\Domain\Exception\InstallRefused;
use App\Module\Template\Domain\Exception\UpgradeRefused;
use App\Module\Template\Domain\Model\ItemKind;
use App\Module\Template\Domain\Model\TemplateInstallation;
use App\Module\Template\Domain\Model\TemplateItem;
use App\Module\Template\Domain\Repository\PersistenceReset;
use App\Module\Template\Domain\Repository\TemplateInstallationRepository;
use App\Module\Template\Domain\Repository\TemplateItemRepository;
use App\Shared\Transaction\TransactionRunner;
use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\FieldTypes\FieldTypeCatalog;
use Fedale\TemplateFormat\FieldTypes\ReferenceKind;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\PackageChecker;
use Fedale\TemplateFormat\SeedRecords;
use Fedale\TemplateFormat\Versions\ItemState;
use Fedale\TemplateFormat\Versions\ItemStates;
use Fedale\TemplateFormat\Versions\VersionCheck;
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
 *
 * Templates requiring templates: the new version is checked against its requirements as
 * installed (one missing or outside its constraint refuses it: upgrade the requirement first),
 * and the new version of a required template against the templates requiring it (one it would
 * break, an item they reference removed or a version past their constraint, refuses it).
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
        private readonly RecordManagerInterface $records,
        private readonly RecordFinderInterface $finder,
        private readonly SeedPlanner $seedPlanner,
        private readonly SeedWriter $seedWriter,
        private readonly SeedFingerprints $fingerprints,
        private readonly RequiredTemplates $required,
    ) {
    }

    /**
     * @param Strategy|Resolution|null $resolution who wins the conflicts: one strategy for all, or
     *                                             item by item; null when there must be none
     *
     * @throws UpgradeRefused nothing was changed
     */
    public function upgrade(Package $theirs, string $source, Strategy|Resolution|null $resolution = null, bool $dryRun = false): UpgradeReport
    {
        $resolution = $resolution instanceof Resolution ? $resolution : new Resolution($resolution);
        $prepared = $this->prepare($theirs, $resolution);
        [$installation, , $mine, $plan, $seeds, $steps, $required] = $prepared;

        $unresolved = [
            ...array_map(static fn (Change $change): string => $change->describe().', changed here too', array_values(array_filter($plan->conflicts(), static fn (Change $change): bool => null === $resolution->for($change->key())))),
            ...array_map(static fn (array $conflict): string => $conflict['description'], array_values(array_filter($seeds->conflicts, static fn (array $conflict): bool => null === $resolution->for($conflict['key'])))),
        ];
        if ([] !== $unresolved) {
            throw UpgradeRefused::conflicts($unresolved);
        }
        $changes = $plan->toApply($resolution);
        $refusals = [...$this->unsupported($changes), ...$this->collisions($changes)];
        if ([] !== $refusals) {
            throw new UpgradeRefused($refusals);
        }

        $report = new UpgradeReport(
            $theirs->name(),
            $installation->getVersion(),
            $theirs->version(),
            $dryRun,
            [
                ...array_map(static fn (Change $change): string => $change->describe(), $changes),
                ...self::stepDescriptions($steps),
                ...$seeds->applied,
            ],
            [
                ...array_map(static fn (Change $change): string => $change->describe(), array_values(array_filter($plan->conflicts(), static fn (Change $change): bool => Strategy::Keep === $resolution->for($change->key())))),
                ...array_map(static fn (array $conflict): string => $conflict['description'], array_values(array_filter($seeds->conflicts, static fn (array $conflict): bool => Strategy::Keep === $resolution->for($conflict['key'])))),
            ],
        );
        try {
            return $this->transactions->run(function () use ($theirs, $mine, $changes, $steps, $seeds, $required, $installation, $source, $report, $dryRun): UpgradeReport {
                $this->apply($theirs, $mine, $changes, $steps, $seeds, $required);
                // The references to the required templates as the new version names them: ids.
                $snapshot = Snapshot::of($this->exporter->ownedState($theirs->name(), $required['names']), $theirs, $this->seedRecords, $this->fingerprints->of($theirs));
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
     * What an upgrade would do, without doing any of it: the changes no one disputes, the
     * conflicts with both versions of each, and the refusals. For the admin, before it asks the
     * user to choose.
     */
    public function preview(Package $theirs): UpgradePreview
    {
        try {
            [$installation, , , $plan, $seeds, $steps] = $this->prepare($theirs, new Resolution());
        } catch (UpgradeRefused $e) {
            $installed = $this->installations->findByName($theirs->name());

            return new UpgradePreview($theirs->name(), $installed?->getVersion() ?? '', $theirs->version(), [], [], $e->reasons);
        }
        $undisputed = $plan->toApply(new Resolution());

        return new UpgradePreview(
            $theirs->name(),
            $installation->getVersion(),
            $theirs->version(),
            [...array_map(static fn (Change $change): string => $change->describe(), $undisputed), ...self::stepDescriptions($steps), ...$seeds->applied],
            [
                ...array_map(static fn (Change $change): ConflictView => new ConflictView(
                    $change->key(),
                    $change->describe(),
                    null === $change->mine ? null : Yaml::dump($change->mine->value, 6, 2),
                    null === $change->theirs ? null : Yaml::dump($change->theirs->value, 6, 2),
                ), $plan->conflicts()),
                ...array_map(static fn (array $conflict): ConflictView => new ConflictView($conflict['key'], $conflict['description']), $seeds->conflicts),
            ],
            [...$this->unsupported($undisputed), ...$this->collisions($undisputed)],
        );
    }

    /**
     * Everything an upgrade decides before it changes anything.
     *
     * @return array{TemplateInstallation, Package, Package, UpgradePlan, SeedChanges, list<array{version: string, from: string, to: string}>, array{packages: list<Package>, names: list<string>, items: RequiredItems}} the installation, the version installed, what the instance holds, the plan, the records, the migration steps, the required templates
     *
     * @throws UpgradeRefused
     */
    private function prepare(Package $theirs, Resolution $resolution): array
    {
        // Its requirements as installed: never upgraded along the way, as at an install.
        $required = $this->required->installed($theirs);
        $problems = $this->checker->check($theirs, $required);
        if ([] !== $problems) {
            throw new UpgradeRefused(array_map(strval(...), $problems));
        }
        $installation = $this->installations->findByName($theirs->name()) ?? throw UpgradeRefused::notInstalled($theirs->name());
        if (!version_compare($theirs->version(), $installation->getVersion(), '>')) {
            throw UpgradeRefused::notNewer($theirs->name(), $installation->getVersion(), $theirs->version());
        }
        // The new version's requirements and the installed one's: a field this instance holds
        // may still point at a template the new version no longer requires.
        $names = array_values(array_unique([...array_map(static fn (Package $requirement): string => $requirement->name(), $required), ...$this->required->requiredBy($theirs->name())]));
        $requirements = ['packages' => $required, 'names' => $names, 'items' => $this->required->items($names)];

        $mine = $this->exporter->ownedState($theirs->name());
        $base = Snapshot::toPackage($installation->getSnapshot());
        $baseStates = $this->states->of($base);
        $theirStates = $this->states->of($theirs);
        $steps = self::migrations($theirs, $installation->getVersion());
        $refusals = [
            ...VersionCheck::problems($baseStates, $theirStates, $installation->getVersion(), $theirs->version(), [] !== $steps),
            ...$this->stepProblems($steps, $baseStates, $theirStates),
            ...$this->dependentProblems($theirs),
        ];
        if ([] !== $refusals) {
            throw new UpgradeRefused($refusals);
        }

        return [
            $installation,
            $base,
            $mine,
            UpgradePlan::compute($baseStates, $theirStates, $this->states->of($mine)),
            $this->seedPlanner->plan($base, $installation->getSnapshot(), $theirs, $mine, $resolution),
            $steps,
            $requirements,
        ];
    }

    /**
     * What the new version would break in the installed templates requiring it: each checked,
     * as this instance holds it now (the user may have added or dropped a reference since), the
     * new version in place of the installed one. Only what the new version breaks is reported:
     * a problem the check finds with the installed version too is not this upgrade's.
     *
     * @return list<string>
     */
    private function dependentProblems(Package $theirs): array
    {
        $problems = [];
        foreach ($this->required->dependents($theirs->name()) as $installation) {
            $installed = Snapshot::toPackage($installation->getSnapshot());
            // Its items now, its manifest (what it requires) and seeds as installed.
            $current = new Package($installed->manifest, [...$this->exporter->ownedState($installation->getName())->documents, ...$installed->documentsOf(DocumentKind::Seed)]);
            $now = $this->required->installed($current);
            $then = array_map(static fn (Package $requirement): Package => $requirement->name() === $theirs->name() ? $theirs : $requirement, $now);
            $before = array_map(strval(...), $this->checker->check($current, $now));
            foreach (array_diff(array_map(strval(...), $this->checker->check($current, $then)), $before) as $problem) {
                $problems[] = \sprintf('%s %s, which requires %s: %s', $installation->getName(), $installation->getVersion(), $theirs->name(), $problem);
            }
        }

        return $problems;
    }

    /**
     * @param list<array{version: string, from: string, to: string}> $steps
     *
     * @return list<string>
     */
    private static function stepDescriptions(array $steps): array
    {
        return array_map(static fn (array $step): string => \sprintf('template.yaml: migration %s: copy_values %s to %s', $step['version'], $step['from'], $step['to']), $steps);
    }

    /**
     * @param list<Change>                                                              $changes
     * @param list<array{version: string, from: string, to: string}>                    $steps
     * @param array{packages: list<Package>, names: list<string>, items: RequiredItems} $required
     */
    private function apply(Package $theirs, Package $mine, array $changes, array $steps, SeedChanges $seeds, array $required): void
    {
        $names = new CurrentNames($this->fieldTypes);
        $names->learn($mine);
        $names->learnRequired($required['items']);
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
        // The migration steps: every field they read from or write to exists now, none is
        // archived yet.
        foreach ($steps as $step) {
            $this->copyValues($step, $names);
        }
        $this->applySeeds($theirs, $seeds, $names, $required);

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

    /**
     * The records of the seeds: every record a reference may name is looked up by its key first,
     * those of the required templates' seeds too, the records of this version written, those it
     * removed deleted.
     *
     * @param array{packages: list<Package>, names: list<string>, items: RequiredItems} $required
     */
    private function applySeeds(Package $theirs, SeedChanges $seeds, CurrentNames $names, array $required): void
    {
        if ([] === $seeds->writes && [] === $seeds->deletes) {
            return;
        }
        $keys = $this->required->records($theirs, $required['packages'], $required['items']);
        foreach ($theirs->documentsOf(DocumentKind::Seed) as $seed) {
            $object = self::string($seed->spec['object'] ?? null);
            foreach ($this->fingerprints->current($names->object($object), self::string($seed->spec['key'] ?? null)) as $value => $record) {
                $keys[$object][$value] = $record->id;
            }
        }
        try {
            $this->seedWriter->write($theirs, new PackageNames($theirs, $required['items']), $seeds->writes, $keys);
        } catch (InstallRefused $e) {
            throw new UpgradeRefused($e->reasons);
        }
        foreach ($seeds->deletes as [$object, $id]) {
            try {
                $this->records->delete($names->object($object), $id);
            } catch (RecordDeleteRestricted $e) {
                throw UpgradeRefused::at('seeds', \sprintf('record %s of "%s"', $id, $names->object($object)), $e->getMessage());
            }
        }
    }

    /**
     * The migration steps of every version after the one installed, in version order.
     *
     * @return list<array{version: string, from: string, to: string}>
     */
    private static function migrations(Package $theirs, string $installed): array
    {
        $migrations = \is_array($theirs->manifest->spec['migrations'] ?? null) ? $theirs->manifest->spec['migrations'] : [];
        $versions = array_values(array_filter(array_map(strval(...), array_keys($migrations)), static fn (string $version): bool => version_compare($version, $installed, '>') && version_compare($version, $theirs->version(), '<=')));
        usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));
        $steps = [];
        foreach ($versions as $version) {
            foreach (self::map($migrations[$version]) as $step) {
                $step = self::map($step);
                $steps[] = ['version' => $version, 'from' => self::string($step['from'] ?? null), 'to' => self::string($step['to'] ?? null)];
            }
        }

        return $steps;
    }

    /**
     * A step copies between two fields of one object, the first of the version installed or the
     * new one, the second of the new one; files are not copied.
     *
     * @param list<array{version: string, from: string, to: string}> $steps
     * @param array<string, ItemState>                               $base
     * @param array<string, ItemState>                               $theirs
     *
     * @return list<string>
     */
    private function stepProblems(array $steps, array $base, array $theirs): array
    {
        $problems = [];
        foreach ($steps as $step) {
            $from = $theirs['field:'.$step['from']] ?? $base['field:'.$step['from']] ?? null;
            $to = $theirs['field:'.$step['to']] ?? null;
            $where = \sprintf('template.yaml: spec.migrations.%s: copy_values %s to %s: ', $step['version'], $step['from'], $step['to']);
            if (null === $from || null === $to) {
                $problems[] = $where.'both fields must be fields of the template.';
            } elseif (self::value($from)['object'] !== self::value($to)['object']) {
                $problems[] = $where.'both fields must be of one object.';
            } elseif ('file' === self::value($from)['type'] || 'file' === self::value($to)['type']) {
                $problems[] = $where.'files cannot be copied.';
            }
        }

        return $problems;
    }

    /**
     * @param array{version: string, from: string, to: string} $step
     */
    private function copyValues(array $step, CurrentNames $names): void
    {
        $object = $names->objectOfField($step['to']);
        $from = $names->field($step['from']);
        $to = $names->field($step['to']);
        foreach ($this->finder->each($object) as $record) {
            try {
                $this->records->update($record->id, new RecordInput($object, [$to => $record->values[$from] ?? null]));
            } catch (RecordValidationFailed $e) {
                throw UpgradeRefused::at('template.yaml', \sprintf('migration %s', $step['version']), \sprintf('record %s of "%s": %s', $record->id, $object, $e->getMessage()));
            }
        }
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
