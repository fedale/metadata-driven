<?php

declare(strict_types=1);

namespace App\TemplateFormat;

/**
 * A data file of a package that is not a document: a seed's CSV file, a file a record holds.
 * Opened on demand, so that a package being exported needs no copy of its files in memory.
 */
interface PackageFile
{
    /**
     * @return resource a readable stream, closed by the caller
     */
    public function open();
}
