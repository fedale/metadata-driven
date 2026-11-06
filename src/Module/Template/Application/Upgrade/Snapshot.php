<?php

declare(strict_types=1);

namespace App\Module\Template\Application\Upgrade;

use Fedale\TemplateFormat\Document;
use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\SeedRecords;

/**
 * What an installation records of its template: the base the next upgrade compares against.
 *
 * Its items are read back from the instance once installed (TemplateExporter::ownedState()),
 * not taken from the package: the app may store an item a little differently than written
 * (a sanitized layout, settings with their defaults), and a base that is not exactly what the
 * instance held would look changed by the user when it was not. The manifest and the seeds,
 * with their records read, come from the package.
 */
final class Snapshot
{
    /**
     * @param array<string, array<string, string>> $fingerprints seed path => key => fingerprint of the record written (SeedFingerprints)
     *
     * @return array<string, array{kind: string, id: string, spec: array<string, mixed>, records?: list<array<string, mixed>>, fingerprints?: array<string, string>}> by path
     */
    public static function of(Package $owned, Package $template, SeedRecords $seedRecords, array $fingerprints = []): array
    {
        $snapshot = [$template->manifest->path => self::document($template->manifest)];
        foreach ($owned->documents as $document) {
            $snapshot[$document->path] = self::document($document);
        }
        foreach ($template->documentsOf(DocumentKind::Seed) as $seed) {
            $snapshot[$seed->path] = self::document($seed) + [
                'records' => $seedRecords->of($template, $seed),
                'fingerprints' => $fingerprints[$seed->path] ?? [],
            ];
        }

        return $snapshot;
    }

    /**
     * The installed version, as a package: its manifest and its documents.
     *
     * @param array<string, mixed> $snapshot
     */
    public static function toPackage(array $snapshot): Package
    {
        $manifest = null;
        $documents = [];
        foreach ($snapshot as $path => $entry) {
            if (!\is_array($entry) || !\is_string($entry['kind'] ?? null) || !\is_string($entry['id'] ?? null)) {
                continue;
            }
            $kind = DocumentKind::from($entry['kind']);
            $spec = [];
            foreach (\is_array($entry['spec'] ?? null) ? $entry['spec'] : [] as $key => $value) {
                $spec[(string) $key] = $value;
            }
            // A seed's records as installed: inline, its CSV file being gone.
            if (DocumentKind::Seed === $kind && \is_array($entry['records'] ?? null)) {
                $spec = ['records' => $entry['records']] + array_diff_key($spec, ['source' => true]);
            }
            $document = new Document($kind, $entry['id'], $spec, (string) $path);
            if (DocumentKind::Template === $kind) {
                $manifest = $document;
            } else {
                $documents[] = $document;
            }
        }

        return new Package($manifest ?? throw new \LogicException('An installation records its manifest.'), $documents);
    }

    /**
     * The fingerprints of the records a seed wrote, by key; none for an install that predates
     * them.
     *
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, string>
     */
    public static function fingerprints(array $snapshot, string $path): array
    {
        $entry = $snapshot[$path] ?? null;
        $fingerprints = [];
        foreach (\is_array($entry) && \is_array($entry['fingerprints'] ?? null) ? $entry['fingerprints'] : [] as $key => $fingerprint) {
            if (\is_string($fingerprint)) {
                $fingerprints[(string) $key] = $fingerprint;
            }
        }

        return $fingerprints;
    }

    /**
     * @return array{kind: string, id: string, spec: array<string, mixed>}
     */
    private static function document(Document $document): array
    {
        return ['kind' => $document->kind->value, 'id' => $document->id, 'spec' => $document->spec];
    }
}
