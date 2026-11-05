<?php

declare(strict_types=1);

namespace App\TemplateFormat;

/**
 * The template format: what every document and every package must look like.
 *
 * App\TemplateFormat depends on nothing else in App (deptrac.template-format.yaml): it is meant
 * to move into a package of its own, fedale/template-format, used by the catalog as well.
 */
final class Format
{
    public const string VERSION = 'metadata-driven/v1';

    /** The manifest of a package, at its root. */
    public const string MANIFEST = 'template.yaml';

    /** vendor/name, as Composer names packages: "acme/geo-italy". */
    public const string NAME_PATTERN = '~^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$~';

    /** Semantic versioning, without pre-release or build parts: "1.0.0". */
    public const string VERSION_PATTERN = '~^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$~';

    /** Dot-separated segments, at least two, the first naming the vendor: "acme.product.name". */
    public const string ID_PATTERN = '~^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$~';

    /** The machine names of objects, fields and forms, as the app accepts them. */
    public const string MACHINE_NAME_PATTERN = '~^[a-z][a-z0-9]*(_[a-z0-9]+)*$~';

    /**
     * The first segment of the ids of a template's items: its vendor, as an id segment.
     */
    public static function idPrefix(string $templateName): string
    {
        return str_replace('-', '_', explode('/', $templateName)[0]);
    }
}
