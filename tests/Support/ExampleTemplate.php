<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Fedale\TemplateFormat\DocumentCodec;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\PackageDirectory;

/**
 * The example template the install, upgrade and index tests start from (acme/shop 1.0.0): three
 * objects, a form, two views, translations and seeds with a file. fedale/template-format keeps
 * a copy of its own for its unit tests.
 */
final class ExampleTemplate
{
    public const string DIRECTORY = __DIR__.'/../Fixtures/templates/shop';

    public static function package(): Package
    {
        return (new PackageDirectory(new DocumentCodec()))->read(self::DIRECTORY);
    }
}
