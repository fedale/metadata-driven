<?php

declare(strict_types=1);

namespace App\Module\Template\Application;

use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Template\Application\Upgrade\SeedFingerprints;
use App\Module\Template\Application\Upgrade\Snapshot;
use App\Module\Template\Domain\Model\ItemKind;
use App\Module\Template\Domain\Model\TemplateInstallation;
use App\Module\Template\Domain\Repository\TemplateInstallationRepository;
use App\Module\Template\Domain\Repository\TemplateItemRepository;
use Fedale\TemplateFormat\Constraint;
use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\SeedRecords;

/**
 * The templates a template requires (spec.requires), as installed here: what the format's
 * checker needs to check the references to them, and what the app needs to resolve those
 * references to its own items.
 */
final class RequiredTemplates
{
    public function __construct(
        private readonly TemplateInstallationRepository $installations,
        private readonly TemplateItemRepository $items,
        private readonly MetadataRegistryInterface $metadata,
        private readonly SeedFingerprints $fingerprints,
        private readonly SeedRecords $seedRecords,
    ) {
    }

    /**
     * The installed template owning each object that one owns, by the object's current name.
     *
     * @return array<string, string> object name => template name
     */
    public function owners(): array
    {
        $owned = [];
        foreach ($this->installations->all() as $installation) {
            foreach ($this->items->itemsOf($installation->getName()) as $item) {
                if (ItemKind::Object === $item->getKind()) {
                    $owned[$item->getLocalId()] = $installation->getName();
                }
            }
        }
        $owners = [];
        foreach ($this->metadata->allObjects() as $name => $object) {
            if (isset($owned[$object->id])) {
                $owners[$name] = $owned[$object->id];
            }
        }

        return $owners;
    }

    /**
     * The constraints a new version of a template puts on the installed templates it requires:
     * the one its installed version has, while it still allows the version installed (an export
     * does not narrow what an author chose); else from the version installed on, up to its next
     * major (^major.minor).
     *
     * @param list<string> $names the templates it requires, installed
     *
     * @return array<string, string> name => constraint
     */
    public function constraints(string $template, array $names): array
    {
        $installed = $this->installations->findByName($template);
        $previous = null === $installed ? [] : Snapshot::requires($installed->getSnapshot());
        $constraints = [];
        foreach ($names as $name) {
            $version = $this->installations->findByName($name)?->getVersion() ?? throw new \LogicException(\sprintf('Template "%s" is not installed.', $name));
            $constraint = $previous[$name] ?? null;
            $constraints[$name] = null !== $constraint && self::allows($constraint, $version)
                ? $constraint
                : '^'.implode('.', \array_slice(explode('.', $version), 0, 2));
        }

        return $constraints;
    }

    /**
     * The installed versions of the templates the package requires, as recorded when they were
     * installed or last upgraded (their seeds with the records written). One that is not
     * installed is left out: the checker then reports it as missing.
     *
     * @return list<Package>
     */
    public function installed(Package $package): array
    {
        return $this->packages(array_keys(Snapshot::requirementsOf(\is_array($package->manifest->spec['requires'] ?? null) ? $package->manifest->spec['requires'] : [])));
    }

    /**
     * The installed versions of templates, by name, those not installed left out.
     *
     * @param list<string> $names
     *
     * @return list<Package>
     */
    public function packages(array $names): array
    {
        $installed = [];
        foreach ($names as $name) {
            $installation = $this->installations->findByName($name);
            if (null !== $installation) {
                $installed[] = Snapshot::toPackage($installation->getSnapshot());
            }
        }

        return $installed;
    }

    /**
     * The templates an installed template requires, as its installed version says; none when it
     * is not installed.
     *
     * @return list<string>
     */
    public function requiredBy(string $template): array
    {
        $installation = $this->installations->findByName($template);

        return null === $installation ? [] : array_keys(Snapshot::requires($installation->getSnapshot()));
    }

    /**
     * The installed templates requiring a template, as their installed versions say.
     *
     * @return list<TemplateInstallation>
     */
    public function dependents(string $template): array
    {
        return array_values(array_filter(
            $this->installations->all(),
            static fn (TemplateInstallation $installation): bool => \array_key_exists($template, Snapshot::requires($installation->getSnapshot())),
        ));
    }

    /**
     * The objects and fields the templates own on this instance now, under their ids.
     *
     * @param list<string> $templates
     */
    public function items(array $templates): RequiredItems
    {
        if ([] === $templates) {
            return new RequiredItems();
        }
        $owned = [];
        foreach ($templates as $template) {
            foreach ($this->items->itemsOf($template) as $item) {
                $owned[$item->getKind()->value][$item->getLocalId()] = $item->getItemId();
            }
        }

        $objects = [];
        $fields = [];
        foreach ($this->metadata->allObjects() as $name => $object) {
            $objectId = $owned[ItemKind::Object->value][$object->id] ?? null;
            if (null === $objectId) {
                continue;
            }
            $objects[$objectId] = $name;
            foreach ($object->fields as $fieldName => $field) {
                $fieldId = $owned[ItemKind::Field->value][$field->id] ?? null;
                if (null !== $fieldId) {
                    $fields[$fieldId] = [$name, $fieldName];
                }
            }
        }

        return new RequiredItems($objects, $fields);
    }

    /**
     * The records of the required templates' seeds that a template's seeds point at, found by
     * the value of their key as they are now (the user may have deleted or changed one since:
     * the seed writer then refuses the reference). Only the objects a seeded relation or
     * many-to-many field targets are read.
     *
     * @param list<Package> $required the required templates, as installed
     *
     * @return array<string, array<string, string>> object id => key => record id
     */
    public function records(Package $package, array $required, RequiredItems $items): array
    {
        $seeded = [];
        foreach ($package->documentsOf(DocumentKind::Seed) as $seed) {
            if (\is_string($seed->spec['object'] ?? null)) {
                $seeded[$seed->spec['object']] = true;
            }
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
                $object = $seed->spec['object'] ?? null;
                $key = $seed->spec['key'] ?? null;
                // An object of the requirement archived here since has no records to point at.
                $name = \is_string($object) ? $items->object($object) : null;
                if (null === $name || !\is_string($key) || !isset($targets[$object])) {
                    continue;
                }
                foreach ($this->fingerprints->current($name, self::keyField($requirement, $object, $key, $items)) as $value => $record) {
                    $keys[$object][$value] = $record->id;
                }
            }
        }

        return $keys;
    }

    /**
     * The records of the required templates' objects a seed may point at, for an export: those
     * their seeds wrote (by the key as those seeds have it), found as they are now. Every
     * object of theirs is listed, an object they seed nothing of with no record: another
     * record of this instance would not be on the instance installing the export.
     *
     * @param list<Package> $required the required templates, as installed
     *
     * @return array<string, array<string, string>> object name => record id => key
     */
    public function seededKeys(array $required, RequiredItems $items): array
    {
        $keys = array_fill_keys(array_keys($items->objectIds()), []);
        foreach ($required as $requirement) {
            foreach ($requirement->documentsOf(DocumentKind::Seed) as $seed) {
                $object = $seed->spec['object'] ?? null;
                $key = $seed->spec['key'] ?? null;
                $name = \is_string($object) ? $items->object($object) : null;
                if (null === $name || !\is_string($key)) {
                    continue;
                }
                $seeded = [];
                foreach ($this->seedRecords->of($requirement, $seed) as $row) {
                    $value = $row[$key] ?? null;
                    if (\is_scalar($value)) {
                        $seeded[trim((string) $value)] = true;
                    }
                }
                foreach ($this->fingerprints->current($name, self::keyField($requirement, $object, $key, $items)) as $value => $record) {
                    if (isset($seeded[$value])) {
                        $keys[$name][$record->id] = (string) $value;
                    }
                }
            }
        }

        return $keys;
    }

    private static function allows(string $constraint, string $version): bool
    {
        try {
            return Constraint::parse($constraint)->allows($version);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * The name a required seed's key field has now: the seed names it as it was installed, and
     * the user may have renamed it since.
     */
    private static function keyField(Package $requirement, string $objectId, string $key, RequiredItems $items): string
    {
        foreach ($requirement->documentsOf(DocumentKind::Object) as $object) {
            if ($object->id !== $objectId) {
                continue;
            }
            foreach (self::map($object->spec['fields'] ?? []) as $field) {
                $field = self::map($field);
                if ($key === ($field['name'] ?? null) && \is_string($field['id'] ?? null)) {
                    return $items->field($field['id']) ?? $key;
                }
            }
        }

        return $key;
    }

    /**
     * @return array<mixed>
     */
    private static function map(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }
}
