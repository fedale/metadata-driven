<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Workspace;

use App\Shared\Workspace\Plans;
use App\Shared\Workspace\SystemConsole;
use App\Shared\Workspace\Workspace;
use App\Shared\Workspace\WorkspaceAdministration;
use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Uid\Uuid;

/**
 * Creates, suspends, resumes and deletes workspaces, in the system session only
 * (WorkspaceSwitcher::system()): where row-level security applies, a workspace's session may not
 * write another workspace's rows.
 *
 * A workspace is its row of core.workspace, its row of core.metadata_state, its data schema (empty:
 * the Schema module fills it from the workspace's metadata) and its RBAC schema with
 * fedale/rbac-bundle's tables. It starts with no objects, members or permissions: workspace:create
 * adds its permissions, its first admin and a package if asked.
 */
final class WorkspaceProvisioner implements WorkspaceAdministration
{
    private const string SLUG = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    /**
     * fedale/rbac-bundle's tables, as migration Version20261021040733 created them and
     * Version20261114100000 changed them (auth_assignment.tenant_id, bundle 1.2), in the
     * schema %1$s. When the bundle's mapping changes, a migration changes every workspace's
     * schema, and this with it.
     */
    private const array RBAC_DDL = [
        'CREATE TABLE %1$s.auth_rule (name VARCHAR(64) NOT NULL, service_id VARCHAR(255) DEFAULT NULL, expression TEXT DEFAULT NULL, data JSON DEFAULT \'[]\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (name))',
        'CREATE TABLE %1$s.auth_item (name VARCHAR(64) NOT NULL, type VARCHAR(32) NOT NULL, description TEXT DEFAULT NULL, data JSON DEFAULT \'[]\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, rule_name VARCHAR(64) DEFAULT NULL, PRIMARY KEY (name))',
        'CREATE INDEX idx_auth_item_type ON %1$s.auth_item (type)',
        'CREATE INDEX IDX_313DC5AADE72171 ON %1$s.auth_item (rule_name)',
        'CREATE TABLE %1$s.auth_item_child (parent VARCHAR(64) NOT NULL, child VARCHAR(64) NOT NULL, PRIMARY KEY (parent, child))',
        'CREATE INDEX IDX_1611424D3D8E604F ON %1$s.auth_item_child (parent)',
        'CREATE INDEX IDX_1611424D22B35429 ON %1$s.auth_item_child (child)',
        'CREATE TABLE %1$s.auth_assignment (user_id VARCHAR(255) NOT NULL, tenant_id VARCHAR(64) DEFAULT \'global\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, item_name VARCHAR(64) NOT NULL, PRIMARY KEY (item_name, user_id, tenant_id))',
        'CREATE INDEX idx_auth_assignment_user ON %1$s.auth_assignment (user_id, tenant_id)',
        'CREATE INDEX IDX_2EC0490E96133AFD ON %1$s.auth_assignment (item_name)',
        'ALTER TABLE %1$s.auth_assignment ADD CONSTRAINT FK_2EC0490E96133AFD FOREIGN KEY (item_name) REFERENCES %1$s.auth_item (name) ON DELETE CASCADE NOT DEFERRABLE',
        'ALTER TABLE %1$s.auth_item ADD CONSTRAINT FK_313DC5AADE72171 FOREIGN KEY (rule_name) REFERENCES %1$s.auth_rule (name) ON DELETE SET NULL NOT DEFERRABLE',
        'ALTER TABLE %1$s.auth_item_child ADD CONSTRAINT FK_1611424D3D8E604F FOREIGN KEY (parent) REFERENCES %1$s.auth_item (name) ON DELETE CASCADE NOT DEFERRABLE',
        'ALTER TABLE %1$s.auth_item_child ADD CONSTRAINT FK_1611424D22B35429 FOREIGN KEY (child) REFERENCES %1$s.auth_item (name) ON DELETE CASCADE NOT DEFERRABLE',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly WorkspaceRegistry $registry,
        private readonly ConnectionCurrentWorkspace $current,
        #[Target('record_files.storage')]
        private readonly FilesystemOperator $files,
        #[Autowire('%app.workspace.console_label%')]
        private readonly string $consoleLabel = 'console',
        private readonly ?Plans $plans = null,
    ) {
    }

    public function create(string $slug, string $name): Workspace
    {
        $this->assertSystem();
        if (1 !== preg_match(self::SLUG, $slug)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no workspace slug: lower case letters, digits and dashes, 63 at most.', $slug));
        }
        if (\in_array($slug, [...SystemConsole::RESERVED_SLUGS, $this->consoleLabel], true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is reserved: choose another slug.', $slug));
        }
        if (null !== $this->registry->bySlug($slug)) {
            throw new \InvalidArgumentException(\sprintf('There is a workspace "%s" already.', $slug));
        }

        // Named after the id, not the slug, which may change: the random end of a UUID v7.
        $id = Uuid::v7()->toRfc4122();
        $base = 't_'.substr(str_replace('-', '', $id), -12);
        $dataSchema = $base.'_data';
        $rbacSchema = $base.'_rbac';

        $this->connection->transactional(function () use ($id, $slug, $name, $dataSchema, $rbacSchema): void {
            $this->connection->executeStatement(
                'INSERT INTO core.workspace (id, slug, name, data_schema, rbac_schema) VALUES (?, ?, ?, ?, ?)',
                [$id, $slug, $name, $dataSchema, $rbacSchema],
            );
            $this->connection->executeStatement('INSERT INTO core.metadata_state (workspace_id) VALUES (?)', [$id]);
            $this->connection->executeStatement(\sprintf('CREATE SCHEMA %s', $dataSchema));
            $this->connection->executeStatement(\sprintf('CREATE SCHEMA %s', $rbacSchema));
            foreach (self::RBAC_DDL as $statement) {
                $this->connection->executeStatement(\sprintf($statement, $rbacSchema));
            }
        });

        return $this->registry->byId($id) ?? throw new \LogicException(\sprintf('Workspace "%s" was not created.', $slug));
    }

    /**
     * A suspended workspace answers 404 to every request, and its commands refuse to run; its data
     * stays as it is.
     */
    public function suspend(Workspace $workspace): void
    {
        $this->setStatus($workspace, 'suspended');
    }

    public function resume(Workspace $workspace): void
    {
        $this->setStatus($workspace, 'active');
    }

    public function assignPlan(Workspace $workspace, string $plan): void
    {
        $this->assertSystem();
        if (null === $this->plans?->get($plan)) {
            throw new \InvalidArgumentException(\sprintf('There is no plan "%s": %s.', $plan, implode(', ', array_keys($this->plans?->all() ?? []))));
        }
        $this->connection->executeStatement('UPDATE core.workspace SET plan = ? WHERE id = ?', [$plan, $workspace->id]);
    }

    /**
     * Drops the workspace's schemas (its records, its roles) and deletes its rows of every core
     * table (the foreign keys to core.workspace cascade), in one transaction; then the directory of
     * its files in the record_files storage, named after its id (the File module's
     * StorageKeys), once nothing can roll the deletion back.
     */
    public function delete(Workspace $workspace): void
    {
        $this->assertSystem();
        $this->connection->transactional(function () use ($workspace): void {
            // The schemas first: the record tables reference core.file, RESTRICT.
            $this->connection->executeStatement(\sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $workspace->dataSchema));
            $this->connection->executeStatement(\sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $workspace->rbacSchema));
            $this->connection->executeStatement('DELETE FROM core.workspace WHERE id = ?', [$workspace->id]);
        });
        // Inside a caller's transaction (the tests'), the rows are not gone yet: the bytes stay.
        if (!$this->connection->isTransactionActive()) {
            $this->files->deleteDirectory(strtolower($workspace->id));
        }
    }

    public function overview(): array
    {
        /** @var array<string, int|string> $members */
        $members = $this->connection->fetchAllKeyValue('SELECT workspace_id, count(*) FROM core.workspace_membership GROUP BY workspace_id');
        // As the system: every workspace's rows.
        /** @var array<string, int|string> $objects */
        $objects = $this->connection->fetchAllKeyValue('SELECT workspace_id, count(*) FROM core.metadata_object GROUP BY workspace_id');

        return array_map(static fn (Workspace $workspace): array => [
            'workspace' => $workspace,
            'members' => (int) ($members[$workspace->id] ?? 0),
            'objects' => (int) ($objects[$workspace->id] ?? 0),
        ], $this->registry->all());
    }

    private function setStatus(Workspace $workspace, string $status): void
    {
        $this->assertSystem();
        $this->connection->executeStatement('UPDATE core.workspace SET status = ? WHERE id = ?', [$status, $workspace->id]);
    }

    private function assertSystem(): void
    {
        if ($this->current->isSelected()) {
            throw new \LogicException('Workspaces are managed in the system session: leave the current workspace first.');
        }
    }
}
