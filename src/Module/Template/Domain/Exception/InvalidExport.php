<?php

declare(strict_types=1);

namespace App\Module\Template\Domain\Exception;

use App\Module\Template\Contract\Exception\TemplateException;

final class InvalidExport extends TemplateException
{
    public static function invalidName(string $name): self
    {
        return new self(\sprintf('"%s" is not a template name: vendor/name, lowercase letters, digits and hyphens (e.g. acme/shop).', $name));
    }

    public static function invalidVersion(string $version): self
    {
        return new self(\sprintf('"%s" is not a version: major.minor.patch, e.g. 1.0.0.', $version));
    }

    public static function nothingToExport(): self
    {
        return new self('Nothing to export: name at least one object or form.');
    }

    public static function outsideTheExport(string $object, string $setting, string $target): self
    {
        return new self(\sprintf('Object "%s" points at "%s" (%s), which is not exported: add it to the objects.', $object, $target, $setting));
    }

    public static function ownedByAnother(string $object, string $template): self
    {
        return new self(\sprintf('Object "%s" belongs to the installed template %s: export it with that one. A template requiring %s can point at it.', $object, $template, $template));
    }

    public static function notOwnedByRequirement(string $object, string $setting, string $target, string $template): self
    {
        return new self(\sprintf('Object "%s" points at "%s" (%s), which was added here to an object of %s: a template can point only at what the templates it requires own.', $object, $target, $setting, $template));
    }

    public static function unseededRecord(string $object, string $field, string $target, string $id): self
    {
        return new self(\sprintf('The records of "%s" point at record %s of "%s" (%s), which the template owning "%s" does not seed: an instance installing the export would not have it.', $object, $id, $target, $field, $target));
    }

    public static function formOutsideTheExport(string $form, string $object): self
    {
        return new self(\sprintf('Form "%s" writes object "%s", which is not exported: add it to the objects.', $form, $object));
    }

    public static function seedOfNoObject(string $object): self
    {
        return new self(\sprintf('A seed of "%s" needs the object itself: add it to the objects.', $object));
    }

    public static function noKey(string $object, string $why): self
    {
        return new self(\sprintf('The records of "%s" need a key, a unique field naming each record: %s.', $object, $why));
    }

    public static function recordWithoutKey(string $object, string $key, string $id): self
    {
        return new self(\sprintf('Record %s of "%s" has no %s: every exported record needs its key.', $id, $object, $key));
    }

    public static function seedOutsideTheExport(string $object, string $field, string $target): self
    {
        return new self(\sprintf('The records of "%s" point at records of "%s" (%s): export its records too.', $object, $target, $field));
    }

    public static function keyWithSeparator(string $object, string $key): self
    {
        return new self(\sprintf('The key "%s" of a record of "%s" holds "|", which separates the keys of a many-to-many field.', $key, $object));
    }

    /**
     * @param list<string> $problems
     */
    public static function brokenPackage(array $problems): self
    {
        // Every item comes from the app, already checked: this is a bug in the export.
        return new self(\sprintf('The export does not follow the template format: %s', implode('; ', $problems)));
    }
}
