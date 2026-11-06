<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Removes a temporary directory a test wrote into, with everything in it.
 */
trait RemovesDirectories
{
    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
