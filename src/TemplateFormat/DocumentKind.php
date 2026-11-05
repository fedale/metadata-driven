<?php

declare(strict_types=1);

namespace App\TemplateFormat;

enum DocumentKind: string
{
    /** The manifest: the template's name, version and what it requires. */
    case Template = 'Template';
    /** An object and its fields. */
    case Object = 'Object';
    /** A composite form. */
    case Form = 'Form';
    /** A form layout or a grid view of an object. */
    case View = 'View';
    /** The texts of one language. */
    case Translations = 'Translations';
    /** Records of one object (phase 3 of the template plan). */
    case Seed = 'Seed';

    /**
     * The directory of the package its documents are written to; the manifest is at the root.
     */
    public function directory(): string
    {
        return match ($this) {
            self::Template => '',
            self::Object => 'objects',
            self::Form => 'forms',
            self::View => 'views',
            self::Translations => 'translations',
            self::Seed => 'seeds',
        };
    }
}
