<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract;

use App\Module\Metadata\Contract\Dto\ViewKind;
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
     * Gives an object a new machine name: its table, the relations pointing at it, the
     * composite forms naming it, its translations and its permissions follow.
     *
     * @return list<string> the definitions written by hand that were rewritten (composite forms)
     */
    public function renameObject(string $objectName, string $newName): array;

    /**
     * Gives a field a new machine name: its column, what names it in the metadata (label and
     * searched fields, conditions, cascades), the views of its object, the composite forms and
     * its translations follow.
     *
     * @return list<string> the definitions written by hand that were rewritten (views, composite forms)
     */
    public function renameField(string $objectName, string $fieldName, string $newName): array;

    /**
     * Takes an object out of the application; its table and records stay. Refused while the
     * metadata uses it (a relation, a composite form): the message lists every use.
     */
    public function archiveObject(string $objectName): void;

    public function restoreObject(string $objectName): void;

    /**
     * Takes a field out of the application; its column and values stay. Refused while the
     * metadata uses it (a view, a composite form, a condition, a cascade).
     */
    public function archiveField(string $objectName, string $fieldName): void;

    public function restoreField(string $objectName, string $fieldName): void;

    /**
     * Forgets an archived object for good: its table and records, its translations, its
     * permissions. Cannot be undone.
     */
    public function purgeObject(string $objectName): void;

    /**
     * Forgets an archived field for good: its column and values, its translations. Cannot be
     * undone.
     */
    public function purgeField(string $objectName, string $fieldName): void;

    /**
     * Changes some settings of a field; the others keep their value. The settings that shape the
     * column are fixed, and an option still held by records cannot be removed.
     *
     * @param array<string, mixed> $settings
     */
    public function configureFieldSettings(string $objectName, string $fieldName, array $settings): void;

    /**
     * Makes a field apply only while another field of the object (a choice or a yes/no) holds
     * one of the values; $when null removes the condition.
     *
     * @param list<string> $values
     */
    public function configureFieldCondition(string $objectName, string $fieldName, ?string $when, array $values = []): void;

    /**
     * @param string|null $field null for the default (the first text-like field)
     */
    public function configureLabel(string $objectName, ?string $field): void;

    /**
     * @param string|null       $handler null for the default handler
     * @param list<string>|null $fields  null for the default fields
     */
    public function configureSearch(string $objectName, ?string $handler, ?array $fields): void;

    /**
     * Creates the view, or replaces the template of the existing one with that kind and name.
     *
     * @param string $template  HTML with {field} tokens; sanitized before it is stored
     * @param bool   $isDefault make it the default of its kind (the first view of a kind always is)
     *
     * @return string the id of the view
     */
    public function saveView(string $objectName, ViewKind $kind, string $name, string $template, bool $isDefault = false): string;

    public function deleteView(string $objectName, ViewKind $kind, string $name): void;

    /**
     * Creates a composite form, or replaces the definition of an existing one. Every error of
     * the definition is reported at once, under the "definition" input.
     *
     * @param string $definition YAML: label, object, fields, service_fields, sections
     *
     * @return string the id of the form
     */
    public function saveForm(string $name, string $definition): string;

    /**
     * Deletes the form only: the records it wrote stay.
     */
    public function deleteForm(string $name): void;

    /**
     * Sets the text of a key in a language, or removes it when $text is null or blank. A key
     * of the metadata (TranslationKey) takes a text in the other languages only; a text key
     * ("text.<name>", placed by {t:name} tokens) is defined by its text in the default language.
     */
    public function translate(string $locale, string $key, ?string $text): void;
}
