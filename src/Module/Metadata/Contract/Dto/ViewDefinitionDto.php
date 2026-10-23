<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * Read-only snapshot of a view: an HTML layout where each {field} token stands for
 * the rendering of that field.
 */
final readonly class ViewDefinitionDto
{
    /** A {field} token: the machine name of a field between braces. */
    public const string TOKEN_PATTERN = '/\{\s*([a-z][a-z0-9_]*)\s*\}/';

    /**
     * @param string $template sanitized HTML with {field} tokens
     */
    public function __construct(
        public string $id,
        public ViewKind $kind,
        public string $name,
        public string $template,
        public bool $isDefault,
    ) {
    }

    /**
     * @return list<string> the field names of the tokens, in order of appearance
     */
    public static function tokens(string $template): array
    {
        preg_match_all(self::TOKEN_PATTERN, $template, $matches);

        return $matches[1];
    }
}
