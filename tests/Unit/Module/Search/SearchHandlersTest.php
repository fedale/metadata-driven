<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Search;

use App\Module\Search\Application\SearchHandlerRegistry;
use App\Module\Search\Contract\Dto\SearchClause;
use App\Module\Search\Contract\Dto\SearchMatch;
use App\Module\Search\Domain\Handler\FreeTextMultiFieldHandler;
use App\Module\Search\Domain\Handler\PhraseHandler;
use App\Module\Search\Domain\SearchTokenizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchHandlersTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('terms')]
    public function testTokenizer(string $term, array $expected): void
    {
        self::assertSame($expected, SearchTokenizer::tokenize($term));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function terms(): iterable
    {
        yield 'words, lowercased' => ['John LONDON', ['john', 'london']];
        yield 'extra whitespace' => ["  john \t  london  ", ['john', 'london']];
        yield 'quoted phrase kept whole' => ['"New  York" john', ['new york', 'john']];
        yield 'duplicates removed' => ['john John JOHN', ['john']];
        yield 'unicode' => ['ÉLODIE Müller', ['élodie', 'müller']];
        yield 'empty quotes ignored' => ['"" john', ['john']];
        yield 'blank' => ['   ', []];
        yield 'bounded' => ['a b c d e f g h i j', ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']];
    }

    public function testMultiFieldHandlerRequiresEveryWordInAnyField(): void
    {
        $clauses = (new FreeTextMultiFieldHandler())->clauses('London John', ['first_name', 'city']);

        self::assertEquals([
            new SearchClause(['first_name', 'city'], SearchMatch::Contains, 'london'),
            new SearchClause(['first_name', 'city'], SearchMatch::Contains, 'john'),
        ], $clauses);
    }

    public function testPhraseHandlerKeepsTheWholeTerm(): void
    {
        self::assertEquals(
            [new SearchClause(['code'], SearchMatch::Contains, 'it 001')],
            (new PhraseHandler())->clauses('  IT   001 ', ['code']),
        );
        self::assertSame([], (new PhraseHandler())->clauses('   ', ['code']));
    }

    public function testRegistryIndexesHandlersByName(): void
    {
        $registry = new SearchHandlerRegistry([new PhraseHandler(), new FreeTextMultiFieldHandler()]);

        self::assertSame(['free_text_multi_field', 'phrase'], $registry->names());
        self::assertTrue($registry->supports('phrase'));
        self::assertFalse($registry->supports('fuzzy'));
        self::assertInstanceOf(PhraseHandler::class, $registry->get('phrase'));
    }

    public function testRegistryRejectsDuplicateNames(): void
    {
        $this->expectException(\LogicException::class);

        new SearchHandlerRegistry([new PhraseHandler(), new PhraseHandler()]);
    }
}
