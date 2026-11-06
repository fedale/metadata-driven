<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\Package;

/**
 * The ids of a package's objects, fields and forms by their names: a translation key names
 * them, and is compared by ids, so that a renamed field keeps its translations.
 *
 * @internal used by ItemStates
 */
final class PackageIds
{
    /**
     * @param array<string, string>                $objects object name => id
     * @param array<string, array<string, string>> $fields  object name => field name => id
     * @param array<string, string>                $forms   form name => id
     */
    private function __construct(
        private readonly array $objects,
        private readonly array $fields,
        private readonly array $forms,
    ) {
    }

    public static function of(Package $package): self
    {
        $objects = [];
        $fields = [];
        foreach ($package->documentsOf(DocumentKind::Object) as $object) {
            $name = \is_string($object->spec['name'] ?? null) ? $object->spec['name'] : '';
            $objects[$name] = $object->id;
            foreach (\is_array($object->spec['fields'] ?? null) ? $object->spec['fields'] : [] as $field) {
                if (\is_array($field) && \is_string($field['name'] ?? null) && \is_string($field['id'] ?? null)) {
                    $fields[$name][$field['name']] = $field['id'];
                }
            }
        }
        $forms = [];
        foreach ($package->documentsOf(DocumentKind::Form) as $form) {
            if (\is_string($form->spec['name'] ?? null)) {
                $forms[$form->spec['name']] = $form->id;
            }
        }

        return new self($objects, $fields, $forms);
    }

    /**
     * A translation key with its names turned into ids ("field.product.name" becomes
     * "field|acme.product|acme.product.name"); a name the package does not know stays.
     */
    public function translationKey(string $key): string
    {
        $parts = explode('.', $key);
        $kind = $parts[0];
        if (\in_array($kind, ['object', 'field', 'inverse', 'message', 'option'], true) && isset($parts[1])) {
            $object = $parts[1];
            $parts[1] = $this->objects[$object] ?? $object;
            if ('object' !== $kind && isset($parts[2])) {
                $parts[2] = $this->fields[$object][$parts[2]] ?? $parts[2];
            }
        } elseif (\in_array($kind, ['form', 'section', 'description', 'service'], true) && isset($parts[1])) {
            $parts[1] = $this->forms[$parts[1]] ?? $parts[1];
        }

        return implode('|', $parts);
    }
}
