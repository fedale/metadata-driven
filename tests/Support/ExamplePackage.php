<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Fedale\PackageFormat\DocumentCodec;
use Fedale\PackageFormat\Package;
use Fedale\PackageFormat\PackageDirectory;

/**
 * The example package the install, upgrade and index tests start from (acme/shop 1.0.0): three
 * objects, a form, two views, translations and seeds with a file.
 * fedale/metadata-driven-package-format keeps a copy of its own for its unit tests.
 *
 * Two more for packages requiring packages: acme/places 1.2.0 (regions and provinces, seeded)
 * and acme/people 1.0.0, which requires acme/places ^1.0 and points at its objects.
 */
final class ExamplePackage
{
    public const string DIRECTORY = __DIR__.'/../Fixtures/packages/shop';

    public const string PLACES = __DIR__.'/../Fixtures/packages/places';

    public const string PEOPLE = __DIR__.'/../Fixtures/packages/people';

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
