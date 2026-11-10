<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Fedale\TemplateFormat\DocumentCodec;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\PackageDirectory;

/**
 * The example template the install, upgrade and index tests start from (acme/shop 1.0.0): three
 * objects, a form, two views, translations and seeds with a file.
 * fedale/metadata-driven-template-format keeps a copy of its own for its unit tests.
 *
 * Two more for templates requiring templates: acme/places 1.2.0 (regions and provinces, seeded)
 * and acme/people 1.0.0, which requires acme/places ^1.0 and points at its objects.
 */
final class ExampleTemplate
{
    public const string DIRECTORY = __DIR__.'/../Fixtures/templates/shop';

    public const string PLACES = __DIR__.'/../Fixtures/templates/places';

    public const string PEOPLE = __DIR__.'/../Fixtures/templates/people';

    public static function package(): Package
    {
        return self::read(self::DIRECTORY);
    }

    public static function places(): Package
    {
        return self::read(self::PLACES);
    }

    public static function people(): Package
    {
        return self::read(self::PEOPLE);
    }

    private static function read(string $directory): Package
    {
        return (new PackageDirectory(new DocumentCodec()))->read($directory);
    }
}
