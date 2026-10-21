<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Search;

use App\Module\Metadata\Application\Command\ConfigureObjectSearchCommand;
use App\Module\Metadata\Application\Handler\ConfigureObjectSearchHandler;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Metadata\Domain\Exception\InvalidMetadataDefinition;
use App\Module\Record\Application\Grid\RecordGridSourceInterface;
use App\Module\Search\Contract\SearchConfigurationInterface;
use App\Tests\Support\CreatesContactObject;
use Doctrine\DBAL\Connection;
use Fedale\GridviewBundle\DataSource\GridQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Search configured in the metadata, run by the record grid source on PostgreSQL.
 */
final class RecordSearchTest extends KernelTestCase
{
    use CreatesContactObject;

    private RecordGridSourceInterface $source;
    private ConfigureObjectSearchHandler $configure;

    protected function setUp(): void
    {
        $container = self::getContainer();
        self::createContacts($container);
        $this->source = $container->get(RecordGridSourceInterface::class);
        $this->configure = $container->get(ConfigureObjectSearchHandler::class);
    }

    /**
     * @param list<string> $expected "first last" names, sorted
     */
    #[DataProvider('multiFieldSearches')]
    public function testEveryWordMustMatchSomeFieldInAnyOrder(string $term, array $expected): void
    {
        self::assertSame($expected, $this->search($term));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function multiFieldSearches(): iterable
    {
        yield 'two fields' => ['John London', ['John Smith']];
        yield 'reversed order' => ['London John', ['John Smith']];
        yield 'case and spacing' => ['  LONDON   john ', ['John Smith']];
        // "john" also occurs inside "Johnson": words match anywhere in a field.
        yield 'one word' => ['john', ['John Doe', 'John Smith', 'Mary Johnson']];
        yield 'quoted phrase' => ['"new york"', ['Anna Rossi']];
        yield 'words of a phrase, unquoted' => ['york new', ['Anna Rossi']];
        yield 'no match' => ['John Rome', []];
    }

    public function testDefaultFieldsAreTheTextFields(): void
    {
        $contact = self::getContainer()->get(MetadataRegistryInterface::class)->getObject('contact');

        self::assertSame(['first_name', 'last_name', 'city'], self::getContainer()->get(SearchConfigurationInterface::class)->fieldsFor($contact));
    }

    public function testConfiguredFieldsAndHandlerAreUsed(): void
    {
        ($this->configure)(new ConfigureObjectSearchCommand('contact', fields: ['first_name', 'last_name']));
        self::assertSame([], $this->search('John London'), 'city is no longer searched');

        ($this->configure)(new ConfigureObjectSearchCommand('contact', handler: 'phrase', fields: ['city']));
        self::assertSame(['Anna Rossi'], $this->search('new york'));
        self::assertSame([], $this->search('york new'), 'a phrase keeps its word order');

        // Back to the defaults.
        ($this->configure)(new ConfigureObjectSearchCommand('contact'));
        self::assertSame(['John Smith'], $this->search('London John'));
    }

    public function testRejectsUnknownFieldsAndHandlers(): void
    {
        try {
            ($this->configure)(new ConfigureObjectSearchCommand('contact', fields: ['first_name', 'nickname']));
            self::fail('Unknown field accepted.');
        } catch (InvalidMetadataDefinition $e) {
            self::assertStringContainsString('"nickname"', $e->getMessage());
        }

        $this->expectException(InvalidMetadataDefinition::class);
        $this->expectExceptionMessage('Unknown search handler "fuzzy"');

        ($this->configure)(new ConfigureObjectSearchCommand('contact', handler: 'fuzzy'));
    }

    public function testTrigramIndexesFollowTheSearchFields(): void
    {
        self::assertSame(['trgm_c_contact_city', 'trgm_c_contact_first_name', 'trgm_c_contact_last_name'], $this->trigramIndexes());

        ($this->configure)(new ConfigureObjectSearchCommand('contact', fields: ['last_name', 'age']));

        // "age" is a number: searched, but not indexed (its text cast is not IMMUTABLE).
        self::assertSame(['trgm_c_contact_last_name'], $this->trigramIndexes());
        self::assertSame(['Mary Johnson'], $this->search('25 john'));
    }

    public function testSearchUsesTheTrigramIndex(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('SET LOCAL enable_seqscan = off');

        $rows = $connection->fetchFirstColumn(
            "EXPLAIN SELECT 1 FROM data.c_contact WHERE LOWER(\"last_name\") LIKE '%ohn%'",
        );
        $plan = implode("\n", array_filter($rows, is_string(...)));

        self::assertStringContainsString('trgm_c_contact_last_name', $plan);
    }

    /**
     * @return list<string> "first last" names of the matching contacts, sorted
     */
    private function search(string $term): array
    {
        $contact = self::getContainer()->get(MetadataRegistryInterface::class)->getObject('contact');
        $fields = self::getContainer()->get(SearchConfigurationInterface::class)->fieldsFor($contact);
        $query = new GridQuery(source: ['object' => 'contact'], searchTerm: $term, searchFields: $fields);

        $names = [];
        foreach ($this->source->fetch($query) as $row) {
            $names[] = \sprintf('%s %s', \is_string($row['first_name'] ?? null) ? $row['first_name'] : '', \is_string($row['last_name'] ?? null) ? $row['last_name'] : '');
        }
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function trigramIndexes(): array
    {
        /** @var list<string> $names */
        $names = self::getContainer()->get(Connection::class)->fetchFirstColumn(
            "SELECT indexname FROM pg_indexes WHERE schemaname = 'data' AND tablename = 'c_contact' AND indexname LIKE 'trgm%' ORDER BY 1",
        );

        return $names;
    }
}
