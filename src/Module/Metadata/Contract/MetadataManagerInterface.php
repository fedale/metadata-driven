<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract;

use App\Module\Metadata\Contract\Exception\InvalidFieldSettings;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\Exception\MetadataInputInvalid;

/**
 * Changes to the definitions, for the modules that edit them (the admin UI). Every change
 * runs in one transaction with the DDL it triggers.
 *
 * @throws MetadataInputInvalid an input breaks a rule (length, format...), keyed by input name
 * @throws InvalidFieldSettings the settings of a field type are invalid
 * @throws MetadataException    any other refusal (duplicate name, unknown object...)
 */
interface MetadataManagerInterface
{
    /**
     * @return string the id of the new object
     */
    public function createObject(string $name, string $labelSingular, string $labelPlural, ?string $icon = null): string;

    /**
     * @param array<string, mixed> $settings
     *
     * @return string the id of the new field
     */
    public function createField(string $objectName, string $name, string $type, string $label, bool $required = false, bool $unique = false, array $settings = []): string;

    public function relabelObject(string $objectName, string $labelSingular, string $labelPlural): void;

    public function relabelField(string $objectName, string $fieldName, string $label): void;

    /**
     * @param string|null $field null for the default (the first text-like field)
     */
    public function configureLabel(string $objectName, ?string $field): void;

    /**
     * @param string|null       $handler null for the default handler
     * @param list<string>|null $fields  null for the default fields
     */
    public function configureSearch(string $objectName, ?string $handler, ?array $fields): void;
}
