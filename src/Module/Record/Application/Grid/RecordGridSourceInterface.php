<?php

declare(strict_types=1);

namespace App\Module\Record\Application\Grid;

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
 * A file field is filtered on whether it holds a file: an Eq criterion with true or false.
 */
interface RecordGridSourceInterface extends GridDataSourceInterface
{
    public const string LABEL_SUFFIX = '__label';
    public const string FILE_SUFFIX = '__file';
}
