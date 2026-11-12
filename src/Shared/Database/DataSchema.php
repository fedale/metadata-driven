<?php

declare(strict_types=1);

namespace App\Shared\Database;

/**
 * The naming of what lives in a tenant's data schema, the PostgreSQL schema holding the record
 * tables generated from its metadata. Each tenant has one, named by core.tenant.data_schema
 * ({@see \App\Shared\Tenant\CurrentTenant::dataSchema()}); the first tenant's is "data".
 * Static tables live in the "core" schema ({@see CoreSchema}) and are managed by Doctrine migrations.
 *
 * The naming is shared by the module building the tables (Schema) and the one reading and
 * writing them (Record).
 */
final class DataSchema
{
    // PostgreSQL silently truncates longer identifiers.
    private const int MAX_IDENTIFIER_LENGTH = 63;
    // "l_<object>__<field>": machine names never hold "__", so no two fields share a name.
    private const string LINK_TABLE_PREFIX = 'l_';
    private const string LINK_TABLE_SEPARATOR = '__';

    /**
     * The link table of a many-to-many field, unqualified.
     *
     * @return non-empty-string
     */
    public static function linkTable(string $objectName, string $columnName): string
    {
        return self::identifier(self::LINK_TABLE_PREFIX.$objectName.self::LINK_TABLE_SEPARATOR.$columnName);
    }

    /**
     * Keeps readable names when they fit, otherwise shortens them with a stable hash.
     *
     * @return non-empty-string
     */
    public static function identifier(string $name): string
    {
        if ('' === $name) {
            throw new \LogicException('Identifiers cannot be empty.');
        }
        if (\strlen($name) <= self::MAX_IDENTIFIER_LENGTH) {
            return $name;
        }

        return substr($name, 0, self::MAX_IDENTIFIER_LENGTH - 9).'_'.substr(hash('sha256', $name), 0, 8);
    }
}
