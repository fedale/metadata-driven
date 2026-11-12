<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Module\File\Application\FileGarbageCollectorInterface;
use App\Module\File\Contract\FileStorageInterface;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Template\Domain\Repository\TemplateSourceRepository;
use App\Shared\Database\TenantScope;
use App\Shared\Tenant\CurrentTenant;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Phase 1 of the multi-tenancy plan: one tenant selected, a second one created by hand next to
 * it, and nothing of the second one reachable from the first.
 */
final class TenantScopeTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testTheInstallationIsItsOnlyTenantWithItsRecordsInTheDataSchema(): void
    {
        $tenant = self::getContainer()->get(CurrentTenant::class);

        self::assertSame($this->connection->fetchOne('SELECT id FROM core.tenant'), $tenant->id());
        self::assertSame('data', $tenant->dataSchema());
    }

    public function testRowsWrittenThroughTheOrmBelongToTheCurrentTenant(): void
    {
        self::getContainer()->get(MetadataManagerInterface::class)->createObject('pet', 'Pet', 'Pets');

        self::assertSame(
            self::getContainer()->get(CurrentTenant::class)->id(),
            $this->connection->fetchOne("SELECT tenant_id FROM core.metadata_object WHERE name = 'pet'"),
        );
    }

    public function testAnotherTenantsRowsAreNeitherFoundNorInTheWayOfANameOfOurs(): void
    {
        $other = $this->createOtherTenant();
        $this->insertSource('acme', 'https://templates.acme.example/index.json', $other);
        $sources = self::getContainer()->get(TemplateSourceRepository::class);

        $theirs = $sources->findByName('acme');
        self::assertNull($theirs);
        self::assertSame([], $sources->all());

        // Names are unique per tenant: ours may be "acme" too.
        $this->insertSource('acme', 'https://templates.example.com/index.json');
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();

        $ours = $sources->findByName('acme');
        self::assertNotNull($ours);
        self::assertSame('https://templates.example.com/index.json', $ours->getUrl());
        self::assertCount(1, $sources->all());
    }

    public function testARowCannotBeWrittenWithoutATenant(): void
    {
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement("SELECT set_config('app.tenant_id', '', false)");
            $this->insertSource('acme', 'https://templates.example.com/index.json');
            self::fail('A row was written without a tenant.');
        } catch (NotNullConstraintViolationException) {
            $this->addToAssertionCount(1);
        } finally {
            // Rolling back the savepoint restores the setting too.
            $this->connection->rollBack();
        }

        self::assertNotFalse($this->connection->fetchOne('SELECT 1 FROM core.tenant WHERE id = '.TenantScope::CURRENT));
    }

    public function testAMetadataChangeBumpsTheRevisionOfItsTenantOnly(): void
    {
        $other = $this->createOtherTenant();
        $ours = self::getContainer()->get(CurrentTenant::class)->id();
        $before = [$this->revision($ours), $this->revision($other)];

        self::getContainer()->get(MetadataManagerInterface::class)->createObject('pet', 'Pet', 'Pets');

        self::assertGreaterThan($before[0], $this->revision($ours));
        self::assertSame($before[1], $this->revision($other));
    }

    public function testTheGarbageCollectorLeavesAnotherTenantsFilesAlone(): void
    {
        $other = $this->createOtherTenant();
        $path = tempnam(sys_get_temp_dir(), 'tenant');
        self::assertIsString($path);
        try {
            file_put_contents($path, 'Oslo chair, assembly');
            $theirs = self::getContainer()->get(FileStorageInterface::class)->store($path, 'assembly.pdf');
        } finally {
            unlink($path);
        }
        $this->connection->executeStatement(
            "UPDATE core.file SET tenant_id = ?, created_at = created_at - INTERVAL '2 days' WHERE id = ?",
            [$other, $theirs->id],
        );

        $report = self::getContainer()->get(FileGarbageCollectorInterface::class)->collect(new \DateTimeImmutable('-1 day'));

        self::assertSame([], $report->files);
        self::assertSame(0, $report->contents, 'The bytes another tenant holds are kept.');
        self::assertNotFalse($this->connection->fetchOne('SELECT 1 FROM core.file WHERE id = ?', [$theirs->id]));
    }

    private function createOtherTenant(): string
    {
        $id = $this->connection->fetchOne(
            "INSERT INTO core.tenant (slug, name, data_schema) VALUES ('globex', 'Globex', 't_globex_data') RETURNING id",
        );
        self::assertIsString($id);
        $this->connection->executeStatement('INSERT INTO core.metadata_state (tenant_id) VALUES (?)', [$id]);

        return $id;
    }

    /**
     * @param string|null $tenant null: the current one, through the trigger
     */
    private function insertSource(string $name, string $url, ?string $tenant = null): void
    {
        $this->connection->executeStatement(
            'INSERT INTO core.template_source (id, name, url, added_at, tenant_id) VALUES (?, ?, ?, now(), ?)',
            [Uuid::v7()->toRfc4122(), $name, $url, $tenant],
        );
    }

    private function revision(string $tenant): int
    {
        $revision = $this->connection->fetchOne('SELECT revision FROM core.metadata_state WHERE tenant_id = ?', [$tenant]);
        self::assertIsInt($revision);

        return $revision;
    }
}
