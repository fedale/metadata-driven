<?php

declare(strict_types=1);

namespace App\TemplateFormat;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads and writes one document as YAML. Reading checks the envelope only: what the spec holds
 * is the PackageChecker's business.
 */
final class DocumentCodec
{
    private const array ENVELOPE = ['format', 'kind', 'id', 'spec'];

    /**
     * @throws InvalidPackage the YAML or the envelope is broken
     */
    public function decode(string $yaml, string $path): Document
    {
        try {
            $data = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            throw new InvalidPackage([new Problem($path, '', \sprintf('Not valid YAML: %s', $e->getMessage()))]);
        }
        if (!\is_array($data) || array_is_list($data) && [] !== $data) {
            throw new InvalidPackage([new Problem($path, '', 'A document is a map with the keys format, kind, id and spec.')]);
        }

        $problems = [];
        foreach (array_keys(array_diff_key($data, array_flip(self::ENVELOPE))) as $key) {
            $problems[] = new Problem($path, (string) $key, 'Unknown key: a document has format, kind, id and spec only.');
        }
        if (Format::VERSION !== ($data['format'] ?? null)) {
            $problems[] = new Problem($path, 'format', \sprintf('Must be "%s".', Format::VERSION));
        }
        $kind = \is_string($data['kind'] ?? null) ? DocumentKind::tryFrom($data['kind']) : null;
        if (null === $kind) {
            $problems[] = new Problem($path, 'kind', \sprintf('Must be one of %s.', implode(', ', array_column(DocumentKind::cases(), 'value'))));
        }
        $id = $data['id'] ?? null;
        $pattern = DocumentKind::Template === $kind ? Format::NAME_PATTERN : Format::ID_PATTERN;
        if (!\is_string($id) || 1 !== preg_match($pattern, $id)) {
            $problems[] = new Problem($path, 'id', DocumentKind::Template === $kind
                ? 'The id of the manifest is the name of the template, vendor/name: lowercase letters, digits and hyphens.'
                : 'Lowercase dot-separated segments, at least two, the first naming the vendor: e.g. acme.product.');
        }
        $spec = $data['spec'] ?? null;
        if (!\is_array($spec) || array_is_list($spec) && [] !== $spec) {
            $problems[] = new Problem($path, 'spec', 'Must be a map.');
        }
        if ([] !== $problems || null === $kind || !\is_string($id) || !\is_array($spec)) {
            throw new InvalidPackage($problems);
        }

        return new Document($kind, $id, self::map($spec), $path);
    }

    /**
     * A YAML map with string keys, as every spec is: a key written as a number ("1: x") is still a
     * name there.
     *
     * @param array<mixed> $map
     *
     * @return array<string, mixed>
     */
    private static function map(array $map): array
    {
        $keyed = [];
        foreach ($map as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    public function encode(Document $document): string
    {
        $data = ['format' => Format::VERSION, 'kind' => $document->kind->value, 'id' => $document->id, 'spec' => $document->spec];

        // Deep enough that no map is written inline; multi-line strings (layouts) as literal blocks.
        return Yaml::dump($data, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_NULL_AS_TILDE);
    }
}
