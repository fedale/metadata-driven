<?php

declare(strict_types=1);

namespace App\Module\Metadata\Contract\Dto;

/**
 * Read-only snapshot of a view. A form view is an HTML layout where each {field} token stands
 * for the rendering of that field; a grid view is a YAML source and its compiled definition.
 */
final readonly class ViewDefinitionDto
{
    /** A {field} token: the machine name of a field between braces. */
    public const string TOKEN_PATTERN = '/\{\s*([a-z][a-z0-9_]*)\s*\}/';
    /** A {t:name} token: the text "text.<name>" in the user's language (see TranslationKey). */
    public const string TEXT_TOKEN_PATTERN = '/\{\s*t:([a-z][a-z0-9_]*)\s*\}/';

    /**
     * @param string           $template sanitized HTML with {field} tokens (form), the YAML source (grid)
     * @param GridViewDto|null $grid     the compiled definition of a grid view
     */
    public function __construct(
        public string $id,
        public ViewKind $kind,
        public string $name,
        public string $template,
        public bool $isDefault,
        public ?GridViewDto $grid = null,
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

    /**
     * @return list<string> the names of the {t:name} tokens, in order of appearance
     */
    public static function textTokens(string $template): array
    {
        preg_match_all(self::TEXT_TOKEN_PATTERN, $template, $matches);

        return $matches[1];
    }
}
