<?php

declare(strict_types=1);

namespace App\Module\Schema\Application\Table;

use App\Module\Schema\Contract\Dto\ColumnMapping;
use App\Module\Schema\Contract\Dto\ColumnProposal;
use App\Module\Schema\Contract\Dto\DatabaseColumn;

/**
 * The fields a column of a table may become, from its type as PostgreSQL writes it, and the one
 * proposed. Each option's settings make the field type ask for a column the column can become
 * without losing anything it can hold: "character varying(80)" a text of 80 characters,
 * "numeric(10,2)" a number with 2 decimals; where it cannot (a bigint as a number, which is an
 * integer column), the adoption's dry run tells when a value does not fit.
 */
final class ColumnProposer
{
    // The field types' own limits (TextFieldType, the "fixed" settings).
    private const int TEXT_VARCHAR_LIMIT = 255;
    private const int TEXT_MAX_LENGTH = 10000;
    private const int MAX_DECIMALS = 10;
    // Decimals proposed for a number written without a scale (a float, a bare numeric).
    private const int FLOAT_DECIMALS = 2;

    /**
     * @param array{object: string, label: string, onDelete: string, table: string|null}|null $target the object a foreign key on
     *                                                                                                the column alone points at (its key),
     *                                                                                                its rule on delete, and the table
     *                                                                                                adopted with this one it is, if so
     */
    public static function propose(DatabaseColumn $column, bool $unique, ?array $target): ColumnProposal
    {
        $name = ProposedNames::machineName($column->name) ?? 'column';
        $label = self::label($name);
        $required = !$column->nullable;
        $field = static fn (string $type, array $settings = []): ColumnMapping => new ColumnMapping($name, $label, $type, self::settings($settings), $required, $unique);
        [$base, $modifiers] = self::parse($column->type);

        $options = [];
        if (null !== $target) {
            $options['relation'] = new ColumnMapping($name, $target['label'], 'relation', ['target' => $target['object'], 'onDelete' => $target['onDelete']], $required, $unique, $target['table']);
        }
        switch ($base) {
            case 'character varying':
            case 'character':
            case 'text':
                $length = $modifiers[0] ?? null;
                $text = null === $length || $length > self::TEXT_VARCHAR_LIMIT
                    ? $field('text', ['maxLength' => min($length ?? self::TEXT_MAX_LENGTH, self::TEXT_MAX_LENGTH), 'multiline' => true])
                    : $field('text', ['maxLength' => $length]);
                // The name tells better than the type: "email", "website", "phone".
                $named = match (true) {
                    1 === preg_match('/(^|_)e?mail($|_)/', $name) => 'email',
                    1 === preg_match('/(^|_)(url|website|site|link)($|_)/', $name) => 'url',
                    1 === preg_match('/(^|_)(phone|tel|telephone|mobile|fax)($|_)/', $name) => 'tel',
                    default => null,
                };
                if (null !== $named) {
                    $options[$named] = $field($named);
                }
                $options['text'] = $text;
                foreach (['email', 'url', 'tel'] as $type) {
                    $options[$type] ??= $field($type);
                }
                break;
            case 'smallint':
            case 'integer':
            case 'bigint':
                $options['number'] = $field('number', ['decimals' => 0]);
                break;
            case 'numeric':
            case 'real':
            case 'double precision':
                $scale = 'numeric' === $base && isset($modifiers[1]) ? min($modifiers[1], self::MAX_DECIMALS) : self::FLOAT_DECIMALS;
                $options['number'] = $field('number', ['decimals' => $scale]);
                break;
            case 'boolean':
                $options['boolean'] = $field('boolean');
                break;
            case 'date':
                $options['date'] = $field('date');
                break;
            case 'timestamp without time zone':
            case 'timestamp with time zone':
                $options['date'] = $field('date', ['includeTime' => true]);
                break;
        }

        return new ColumnProposal($column, $options, array_key_first($options));
    }

    /**
     * @param array<mixed> $settings
     *
     * @return array<string, mixed>
     */
    private static function settings(array $settings): array
    {
        return array_combine(array_map(strval(...), array_keys($settings)), array_values($settings));
    }

    /**
     * "customer_name" reads "Customer name".
     */
    public static function label(string $name): string
    {
        return ucfirst(str_replace('_', ' ', $name));
    }

    /**
     * "character varying(80)" is ["character varying", [80]]; "numeric(10,2)", ["numeric", [10, 2]];
     * "timestamp(3) with time zone", ["timestamp with time zone", [3]].
     *
     * @return array{string, list<int>}
     */
    public static function parse(string $type): array
    {
        $modifiers = [];
        if (1 === preg_match('/\(([\d,\s]+)\)/', $type, $matches)) {
            $modifiers = array_map(intval(...), explode(',', $matches[1]));
        }
        $base = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/\([\d,\s]+\)/', '', $type)));

        return [$base, $modifiers];
    }
}
