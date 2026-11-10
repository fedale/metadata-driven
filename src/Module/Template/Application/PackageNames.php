<?php

declare(strict_types=1);

namespace App\Module\Template\Application;

use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\Package;

/**
 * The names the app will give a template's objects and fields, by id: inside the app items
 * point at each other by name. The ids of the templates it requires resolve to the names their
 * items have here now (RequiredItems); the checker has made sure the two sets of ids differ.
 *
 * @internal used by TemplateInstaller, on a package the checker has validated
 */
final class PackageNames
{
    /** @var array<string, string> object id => name */
    private array $objects = [];

    /** @var array<string, string> field id => name */
    private array $fields = [];

    public function __construct(
        Package $package,
        private readonly RequiredItems $required = new RequiredItems(),
    ) {
        foreach ($package->documentsOf(DocumentKind::Object) as $object) {
            $this->objects[$object->id] = \is_string($object->spec['name'] ?? null) ? $object->spec['name'] : '';
            foreach (\is_array($object->spec['fields'] ?? null) ? $object->spec['fields'] : [] as $field) {
                if (\is_array($field) && \is_string($field['id'] ?? null) && \is_string($field['name'] ?? null)) {
                    $this->fields[$field['id']] = $field['name'];
                }
            }
        }
    }

    public function object(string $id): string
    {
        return $this->objects[$id] ?? $this->required->object($id) ?? throw new \LogicException(\sprintf('No object has the id "%s": the package was not checked.', $id));
    }

    public function field(string $id): string
    {
        return $this->fields[$id] ?? $this->required->field($id) ?? throw new \LogicException(\sprintf('No field has the id "%s": the package was not checked.', $id));
    }

    /**
     * Whether the id names a field of the package itself, not of a template it requires.
     */
    public function isOwnField(string $id): bool
    {
        return isset($this->fields[$id]);
    }
}
