<?php

declare(strict_types=1);

namespace App\Module\Schema\Application\Table;

/**
 * Machine names proposed from the names a database gives its tables and columns: "CustomerName"
 * and "customer-name" both become "customer_name".
 *
 * The rules are the metadata's machine names (lowercase letters, digits and single underscores,
 * starting with a letter, 2 to 50 characters); the Metadata module checks them again when the
 * name is used.
 */
final class ProposedNames
{
    private const string PATTERN = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';
    private const int MAX_LENGTH = 50;

    /**
     * The name an object adopting the table would take: the table's, made a machine name, with
     * the prefix of the object tables ("c_") left out. Null when nothing valid is left.
     */
    public static function forTable(string $table): ?string
    {
        $name = self::machineName($table);

        return null !== $name && str_starts_with($name, 'c_') ? self::machineName(substr($name, 2)) : $name;
    }

    public static function machineName(string $name): ?string
    {
        // A capital after a lower case letter or a digit starts a word: CustomerName, Item2Price.
        $name = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $name);
        $name = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $name), '_'));
        // A name must start with a letter: "2024_sales" becomes "t_2024_sales".
        if ('' !== $name && !ctype_alpha($name[0])) {
            $name = 't_'.$name;
        }
        $name = rtrim(substr($name, 0, self::MAX_LENGTH), '_');

        return \strlen($name) >= 2 && 1 === preg_match(self::PATTERN, $name) ? $name : null;
    }
}
