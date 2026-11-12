<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Domain\Repository\TranslationRepository;
use App\Module\Template\Domain\Repository\TemplateSourceRepository;
use App\Shared\Database\TenantScope;
use App\Shared\Tenant\CurrentTenant;
use App\Tests\Support\CreatesTenants;
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
    use CreatesTenants;

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

    /**
     * Doctrine's filters apply to SELECTs only: a DQL DELETE, or SQL, must name the tenant
     * itself.
     */
    public function testRemovingAndMovingTranslationsLeavesAnotherTenantsAlone(): void
    {
        $other = $this->createOtherTenant();
        foreach (['object.pet.singular', 'object.pet.plural', 'field.pet.name'] as $key) {
            $this->connection->executeStatement(
                "INSERT INTO core.metadata_translation (id, locale, message_key, text, updated_at, tenant_id) VALUES (?, 'it', ?, 'Theirs', now(), ?)",
                [Uuid::v7()->toRfc4122(), $key, $other],
            );
        }
        $translations = self::getContainer()->get(TranslationRepository::class);

        $translations->removeKey('object.pet.singular');
        $translations->removeKeysUnder(['field.pet']);
        $translations->moveKeysUnder(['object.pet' => 'object.animal']);

        self::assertSame(
            ['field.pet.name', 'object.pet.plural', 'object.pet.singular'],
            $this->connection->fetchFirstColumn('SELECT message_key FROM core.metadata_translation WHERE tenant_id = ? ORDER BY 1', [$other]),
        );
    }

    private function createOtherTenant(): string
    {
        return self::createTenant(self::getContainer(), 'globex', 'Globex')->id;
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
