<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Package;

use App\Module\Package\Application\ProblemMessages;
use App\Shared\Message\MessageTranslator;
use Fedale\PackageFormat\Problem;
use Fedale\PackageFormat\Text;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The package format's problems in the language of the page, located as the format locates them.
 */
final class ProblemMessagesTest extends KernelTestCase
{
    public function testAProblemIsReadInTheLanguageOfThePage(): void
    {
        $messages = self::getContainer()->get(MessageTranslator::class);
        $problems = ProblemMessages::all([
            new Problem('seeds/products.yaml', 'spec.source', new Text('no_file', 'No file "%file%" in the package.', ['file' => 'products.csv'])),
            new Problem('', '', new Text('archive_invalid', 'The archive is invalid: %why%', ['why' => new Text('archive_truncated', 'It ends too soon.')])),
            // A schema violation has no key: it stays as the JSON Schema validator wrote it.
            new Problem('objects/product.yaml', 'spec', 'The data (null) must match the type: object'),
        ]);

        self::assertSame([
            'seeds/products.yaml: spec.source: Nel package non c\'è il file "products.csv".',
            'L\'archivio non è valido: Finisce troppo presto.',
            'objects/product.yaml: spec: The data (null) must match the type: object',
        ], $messages->transAll($problems, 'it'));
        // In English, as the format writes it.
        self::assertSame('seeds/products.yaml: spec.source: No file "products.csv" in the package.', (string) $problems[0]);
    }
}
