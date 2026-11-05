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

    public static function formOutsideTheExport(string $form, string $object): self
    {
        return new self(\sprintf('Form "%s" writes object "%s", which is not exported: add it to the objects.', $form, $object));
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
