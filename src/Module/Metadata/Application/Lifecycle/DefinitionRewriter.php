<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Lifecycle;

use Symfony\Component\Yaml\Yaml;

/**
 * Rewrites the definitions written by hand (form views, grid views, composite forms) when an
 * object or a field they name is renamed. Each method returns the source unchanged when it
 * does not name the old name, so that a source is only rewritten when it has to be.
 *
 * An HTML view keeps its text: only its {name} tokens change. A YAML definition is parsed,
 * changed and dumped again: its content is the same, its comments and formatting are lost.
 */
final class DefinitionRewriter
{
    private const int DUMP_INLINE = 10;
    private const int DUMP_INDENT = 2;

    /**
     * The {old} tokens of an HTML template; the {t:name} tokens are no field and stay.
     */
    public function tokens(string $template, string $old, string $new): string
    {
        return (string) preg_replace('/\{\s*'.preg_quote($old, '/').'\s*\}/', '{'.$new.'}', $template);
    }

    /**
     * A composite form whose nodes name a renamed object.
     */
    public function formObject(string $source, string $old, string $new): string
    {
        return $this->rewriteYaml($source, function (array $definition) use ($old, $new): array {
            return $this->walkNodes($definition, static function (array $node) use ($old, $new): array {
                if (($node['object'] ?? null) === $old) {
                    $node['object'] = $new;
                }

                return $node;
            });
        });
    }

    /**
     * A composite form whose nodes of the object name a renamed field: in "fields",
     * "required", the layout, or as the "via" of a section.
     */
    public function formField(string $source, string $object, string $old, string $new): string
    {
        return $this->rewriteYaml($source, function (array $definition) use ($object, $old, $new): array {
            return $this->walkNodes($definition, function (array $node) use ($object, $old, $new): array {
                if (($node['object'] ?? null) !== $object) {
                    return $node;
                }
                foreach (['fields', 'required'] as $key) {
                    if (\is_array($node[$key] ?? null)) {
                        $node[$key] = array_map(static fn (mixed $name): mixed => $name === $old ? $new : $name, $node[$key]);
                    }
                }
                if (\is_string($node['layout'] ?? null)) {
                    $node['layout'] = $this->tokens($node['layout'], $old, $new);
                }
                if (($node['via'] ?? null) === $old) {
                    $node['via'] = $new;
                }

                return $node;
            });
        });
    }

    /**
     * A grid view naming a renamed field: a column ("name" or "field: name"), the {tokens}
     * of a virtual column, a sort key.
     */
    public function gridField(string $source, string $old, string $new): string
    {
        return $this->rewriteYaml($source, function (array $definition) use ($old, $new): array {
            if (\is_array($definition['columns'] ?? null)) {
                $definition['columns'] = array_map(function (mixed $column) use ($old, $new): mixed {
                    if ($column === $old) {
                        return $new;
                    }
                    if (!\is_array($column)) {
                        return $column;
                    }
                    if (($column['field'] ?? null) === $old) {
                        $column['field'] = $new;
                    }
                    if (\is_string($column['template'] ?? null)) {
                        $column['template'] = $this->tokens($column['template'], $old, $new);
                    }

                    return $column;
                }, $definition['columns']);
            }
            if (\is_array($definition['sort'] ?? null)) {
                $sort = [];
                foreach ($definition['sort'] as $key => $direction) {
                    $sort[$key === $old ? $new : $key] = $direction;
                }
                $definition['sort'] = $sort;
            }

            return $definition;
        });
    }

    /**
     * @param callable(array<mixed>): array<mixed> $change
     */
    private function rewriteYaml(string $source, callable $change): string
    {
        $definition = Yaml::parse($source);
        if (!\is_array($definition)) {
            return $source;
        }
        $changed = $change($definition);

        return $changed === $definition ? $source : Yaml::dump($changed, self::DUMP_INLINE, self::DUMP_INDENT, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * Applies the change to the root of a form, then to each of its sections, depth first.
     *
     * @param array<mixed>                         $node
     * @param callable(array<mixed>): array<mixed> $change
     *
     * @return array<mixed>
     */
    private function walkNodes(array $node, callable $change): array
    {
        $node = $change($node);
        if (\is_array($node['sections'] ?? null)) {
            $node['sections'] = array_map(
                fn (mixed $section): mixed => \is_array($section) ? $this->walkNodes($section, $change) : $section,
                $node['sections'],
            );
        }

        return $node;
    }
}
