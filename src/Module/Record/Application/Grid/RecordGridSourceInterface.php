<?php

declare(strict_types=1);

namespace App\Module\Record\Application\Grid;

use App\Module\Metadata\Contract\Dto\ObjectDefinitionDto;
use Fedale\GridviewBundle\Contract\GridDataSourceInterface;

/**
 * Rows of an object table for the record grids.
 *
 * GridQuery::$source must be `['object' => '<object name>']`. Each row is keyed by field
 * name, plus "id"; values are typed as in RecordOutput. A relation field also brings
 * the label of the related record, under "<field>__label"; a many-to-many field the ids of
 * the linked records sorted by their labels, and those labels joined by ", " under
 * "<field>__label" (null when it links to nothing); a file field the files it holds under
 * "<field>__file", a list of `array{id: string, name: string, image: bool}` (empty when it holds
 * none; one file at most for now), and their names under "<field>__label".
 *
 * A file field is filtered on whether it holds a file: an Eq criterion with true or false. A
 * tags field is filtered on its labels, ignoring case: Eq one, In any of them, Contains,
 * StartsWith, EndsWith and NotContains a text; Eq with true or false on whether it holds one.
 */
interface RecordGridSourceInterface extends GridDataSourceInterface
{
    public const string LABEL_SUFFIX = '__label';
    public const string FILE_SUFFIX = '__file';

    /**
     * Runs $read with every unbounded fetch() (the export's, GridQuery::$limit null) cut to $limit
     * rows: for a reader that needs to know only whether there are more rows than it takes, as a
     * grid's selection does, without the whole table read.
     *
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T
     */
    public function atMost(int $limit, callable $read): mixed;

    /**
     * The labels the live records hold in a tags field, each once ignoring case (one of its
     * spellings), in alphabetical order, $limit at most: the choices of its filter.
     *
     * @return list<string>
     */
    public function labelsInUse(ObjectDefinitionDto $object, string $fieldName, int $limit): array;
}
