<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\Metadata\Domain\Repository\TranslationRepository;
use App\Module\Package\Domain\Repository\PackageSourceRepository;
use App\Shared\Database\WorkspaceScope;
use App\Shared\Workspace\CurrentWorkspace;
use App\Tests\Support\CreatesWorkspaces;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Phase 1 of the multi-tenancy plan: one workspace selected, a second one created by hand next to
 * it, and nothing of the second one reachable from the first.
 */
final class WorkspaceScopeTest extends KernelTestCase
{
    use CreatesWorkspaces;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testTheInstallationIsItsOnlyWorkspaceWithItsRecordsInTheDataSchema(): void
    {
        $workspace = self::getContainer()->get(CurrentWorkspace::class);

        self::assertSame($this->connection->fetchOne('SELECT id FROM core.workspace'), $workspace->id());
        self::assertSame('data', $workspace->dataSchema());
    }

    public function testRowsWrittenThroughTheOrmBelongToTheCurrentWorkspace(): void
    {
        self::getContainer()->get(MetadataManagerInterface::class)->createObject('pet', 'Pet', 'Pets');

        self::assertSame(
            self::getContainer()->get(CurrentWorkspace::class)->id(),
            $this->connection->fetchOne("SELECT workspace_id FROM core.metadata_object WHERE name = 'pet'"),
        );
    }

    public function testAnotherWorkspacesRowsAreNeitherFoundNorInTheWayOfANameOfOurs(): void
    {
        $other = $this->createOtherWorkspace();
        $this->insertSource('acme', 'https://packages.acme.example/index.json', $other);
        $sources = self::getContainer()->get(PackageSourceRepository::class);

        $theirs = $sources->findByName('acme');
        self::assertNull($theirs);
        self::assertSame([], $sources->all());

        // Names are unique per workspace: ours may be "acme" too.
        $this->insertSource('acme', 'https://packages.example.com/index.json');
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();

        $ours = $sources->findByName('acme');
        self::assertNotNull($ours);
        self::assertSame('https://packages.example.com/index.json', $ours->getUrl());
        self::assertCount(1, $sources->all());
    }

    public function testARowCannotBeWrittenWithoutAWorkspace(): void
    {
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement("SELECT set_config('app.workspace_id', '', false)");
            // Not package_source: its rows may have no workspace (shared indexes).
            $this->connection->executeStatement(
                "INSERT INTO core.metadata_translation (id, locale, message_key, text, updated_at) VALUES (?, 'it', 'object.pet.singular', 'Animale', now())",
                [Uuid::v7()->toRfc4122()],
            );
            self::fail('A row was written without a workspace.');
        } catch (NotNullConstraintViolationException) {
            $this->addToAssertionCount(1);
        } finally {
            // Rolling back the savepoint restores the setting too.
            $this->connection->rollBack();
        }

        self::assertNotFalse($this->connection->fetchOne('SELECT 1 FROM core.workspace WHERE id = '.WorkspaceScope::CURRENT));
    }

    public function testAMetadataChangeBumpsTheRevisionOfItsWorkspaceOnly(): void
    {
        $other = $this->createOtherWorkspace();
        $ours = self::getContainer()->get(CurrentWorkspace::class)->id();
        $before = [$this->revision($ours), $this->revision($other)];

        self::getContainer()->get(MetadataManagerInterface::class)->createObject('pet', 'Pet', 'Pets');

        self::assertGreaterThan($before[0], $this->revision($ours));
        self::assertSame($before[1], $this->revision($other));
    }

    /**
     * Doctrine's filters apply to SELECTs only: a DQL DELETE, or SQL, must name the workspace
     * itself.
     */
    public function testRemovingAndMovingTranslationsLeavesAnotherWorkspacesAlone(): void
    {
        $other = $this->createOtherWorkspace();
        foreach (['object.pet.singular', 'object.pet.plural', 'field.pet.name'] as $key) {
            $this->connection->executeStatement(
                "INSERT INTO core.metadata_translation (id, locale, message_key, text, updated_at, workspace_id) VALUES (?, 'it', ?, 'Theirs', now(), ?)",
                [Uuid::v7()->toRfc4122(), $key, $other],
            );
        }
        $translations = self::getContainer()->get(TranslationRepository::class);

        $translations->removeKey('object.pet.singular');
        $translations->removeKeysUnder(['field.pet']);
        $translations->moveKeysUnder(['object.pet' => 'object.animal']);

        self::assertSame(
            ['field.pet.name', 'object.pet.plural', 'object.pet.singular'],
            $this->connection->fetchFirstColumn('SELECT message_key FROM core.metadata_translation WHERE workspace_id = ? ORDER BY 1', [$other]),
        );
    }

    private function createOtherWorkspace(): string
    {
        return self::createWorkspace(self::getContainer(), 'globex', 'Globex')->id;
    }

    /**
     * @param string|null $workspace null: the current one, through the trigger
     */
    private function insertSource(string $name, string $url, ?string $workspace = null): void
    {
        $this->connection->executeStatement(
            'INSERT INTO core.package_source (id, name, url, added_at, workspace_id) VALUES (?, ?, ?, now(), ?)',
            [Uuid::v7()->toRfc4122(), $name, $url, $workspace],
        );
    }

    private function revision(string $workspace): int
    {
        $revision = $this->connection->fetchOne('SELECT revision FROM core.metadata_state WHERE workspace_id = ?', [$workspace]);
        self::assertIsInt($revision);

        return $revision;
    }
}
