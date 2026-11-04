<?php

declare(strict_types=1);

namespace App\Module\File\UI\Console;

use App\Module\File\Application\Dto\GarbageReport;
use App\Module\File\Application\FileGarbageCollectorInterface;
use League\Flysystem\FilesystemException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Meant to run on a schedule (cron, a systemd timer). The grace period keeps the files of the
 * uploads in progress: their row is written before the record that will reference it.
 */
#[AsCommand(name: 'metadata:files:gc', description: 'Delete the stored files that no record references any more, with their bytes and thumbnails')]
final class CollectFilesConsoleCommand
{
    private const array UNITS = ['m' => 'minutes', 'h' => 'hours', 'd' => 'days'];

    public function __construct(
        private readonly FileGarbageCollectorInterface $collector,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('List what would be deleted, delete nothing')] bool $dryRun = false,
        #[Option('Keep the files stored more recently than this: minutes (30m), hours (24h) or days (7d)')] string $olderThan = '24h',
    ): int {
        if (1 !== preg_match('/^([1-9]\d{0,5})([mhd])$/', $olderThan, $match)) {
            $io->error(\sprintf('"%s" is not a duration: write a positive number followed by m (minutes), h (hours) or d (days), such as 24h.', $olderThan));

            return Command::INVALID;
        }
        $storedBefore = new \DateTimeImmutable(\sprintf('-%d %s', $match[1], self::UNITS[$match[2]]));

        try {
            $report = $this->collector->collect($storedBefore, $dryRun);
        } catch (FilesystemException $e) {
            // The rows deleted so far stay deleted; their bytes go at the next run.
            $io->error(\sprintf('The file storage failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        if ($report->isEmpty()) {
            $io->success(\sprintf('Nothing to delete among the files stored before %s.', $storedBefore->format('Y-m-d H:i')));

            return Command::SUCCESS;
        }
        if ([] !== $report->files) {
            $io->table(['Id', 'Name', 'Size', 'Stored'], array_map(static fn ($file): array => [
                $file->id,
                $file->originalName,
                self::size($file->size),
                $file->storedAt->format('Y-m-d H:i'),
            ], $report->files));
        }
        $summary = self::summary($report);
        $dryRun ? $io->note('Would delete '.$summary.' (dry run).') : $io->success('Deleted '.$summary.'.');

        return Command::SUCCESS;
    }

    private static function summary(GarbageReport $report): string
    {
        return \sprintf(
            '%d %s, the bytes of %d stored %s (%s) and %d %s',
            \count($report->files),
            1 === \count($report->files) ? 'file' : 'files',
            $report->contents,
            1 === $report->contents ? 'content' : 'contents',
            self::size($report->contentBytes),
            $report->thumbnails,
            1 === $report->thumbnails ? 'thumbnail' : 'thumbnails',
        );
    }

    /**
     * Decimal units, as the maxSize setting of the file fields (1 MB = 1,000,000 bytes).
     */
    private static function size(int $bytes): string
    {
        return match (true) {
            $bytes < 1_000 => \sprintf('%d B', $bytes),
            $bytes < 1_000_000 => \sprintf('%.1f kB', $bytes / 1_000),
            $bytes < 1_000_000_000 => \sprintf('%.1f MB', $bytes / 1_000_000),
            default => \sprintf('%.1f GB', $bytes / 1_000_000_000),
        };
    }
}
