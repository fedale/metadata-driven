<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 4 of docs/internal/vocabulary-rename-plan.md: the package format v2. The snapshot each
 * installation keeps of the version it installed (core.template_installation.snapshot) holds the
 * documents as they were read, in format v1; the app now reads v2 only past the reader, so the
 * snapshots are converted once: the manifest under package.yaml, of kind Package, requiring
 * format v2; each view's template as its source.
 */
final class Version20261115120000 extends AbstractMigration
{
    private const string V1 = 'metadata-driven/v1';
    private const string V2 = 'metadata-driven/v2';

    public function getDescription(): string
    {
        return 'Convert the snapshots of the installations to the package format v2';
    }

    public function up(Schema $schema): void
    {
        $this->convert(static function (array $snapshot): array {
            $converted = [];
            foreach ($snapshot as $path => $entry) {
                if (\is_array($entry) && 'Template' === ($entry['kind'] ?? null)) {
                    $entry['kind'] = 'Package';
                    if (\is_array($entry['spec']['requires'] ?? null) && self::V1 === ($entry['spec']['requires']['format'] ?? null)) {
                        $entry['spec']['requires']['format'] = self::V2;
                    }
                    $path = 'package.yaml';
                } elseif (\is_array($entry) && 'View' === ($entry['kind'] ?? null) && \is_array($entry['spec'] ?? null) && \array_key_exists('template', $entry['spec'])) {
                    $entry['spec'] = self::renameKey($entry['spec'], 'template', 'source');
                }
                $converted[$path] = $entry;
            }

            return $converted;
        });
    }

    public function down(Schema $schema): void
    {
        $this->convert(static function (array $snapshot): array {
            $converted = [];
            foreach ($snapshot as $path => $entry) {
                if (\is_array($entry) && 'Package' === ($entry['kind'] ?? null)) {
                    $entry['kind'] = 'Template';
                    if (\is_array($entry['spec']['requires'] ?? null) && self::V2 === ($entry['spec']['requires']['format'] ?? null)) {
                        $entry['spec']['requires']['format'] = self::V1;
                    }
                    $path = 'template.yaml';
                } elseif (\is_array($entry) && 'View' === ($entry['kind'] ?? null) && \is_array($entry['spec'] ?? null) && \array_key_exists('source', $entry['spec'])) {
                    $entry['spec'] = self::renameKey($entry['spec'], 'source', 'template');
                }
                $converted[$path] = $entry;
            }

            return $converted;
        });
    }

    /**
     * Rewrites every snapshot with $change. The migrations run in the system session, which sees
     * the rows of every workspace.
     *
     * @param callable(array<mixed>): array<mixed> $change
     */
    private function convert(callable $change): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, snapshot FROM core.template_installation') as $row) {
            $snapshot = json_decode((string) $row['snapshot'], true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($snapshot)) {
                continue;
            }
            $this->addSql(
                'UPDATE core.template_installation SET snapshot = ? WHERE id = ?',
                [json_encode($change($snapshot), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), $row['id']],
            );
        }
    }

    /**
     * The map with one key renamed, in its place.
     *
     * @param array<mixed> $map
     *
     * @return array<mixed>
     */
    private static function renameKey(array $map, string $from, string $to): array
    {
        $renamed = [];
        foreach ($map as $key => $value) {
            $renamed[$from === $key ? $to : $key] = $value;
        }

        return $renamed;
    }
}
