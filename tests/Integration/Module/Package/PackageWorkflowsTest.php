<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Package;

use App\Module\Access\Application\PermissionSynchronizer;
use App\Module\Access\Contract\Permissions;
use App\Module\Package\Application\Command\ExportPackageCommand;
use App\Module\Package\Application\PackageExporter;
use App\Module\Package\Application\PackageInstaller;
use App\Module\Package\Application\Upgrade\PackageUpgrader;
use App\Module\Package\Application\Upgrade\Strategy;
use App\Module\Package\Domain\Exception\InstallRefused;
use App\Module\Package\Domain\Exception\InvalidExport;
use App\Module\Package\Domain\Exception\UpgradeRefused;
use App\Module\Package\Domain\Model\ItemKind;
use App\Module\Package\Domain\Repository\PackageItemRepository;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Domain\Model\Workflow;
use App\Module\Workflow\Domain\Repository\WorkflowRepository;
use App\Tests\Support\ExamplePackage;
use Fedale\PackageFormat\Document;
use Fedale\PackageFormat\DocumentKind;
use Fedale\PackageFormat\Package;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Workflows in a package (the example package with two of them, built here): installed as
 * drafts, their objects and the workflows they call by id in the package and by name here;
 * exported back; upgraded as drafts, the active version running on.
 */
final class PackageWorkflowsTest extends KernelTestCase
{
    /** On a product left without seats: a tag named by the called workflow. */
    private const array RESTOCK = [
        'trigger' => ['on' => 'updated', 'object' => 'acme.product', 'when' => 'after', 'fields' => ['seats']],
        'steps' => [
            ['key' => 'empty', 'type' => 'filter', 'when' => ['field' => '$trigger.record.seats', 'op' => 'eq', 'value' => 0]],
            ['key' => 'name', 'type' => 'run_workflow', 'workflow' => 'acme.workflow.tag_name', 'input' => ['sku' => '{{ $trigger.record.sku }}']],
            ['key' => 'tag', 'type' => 'create_record', 'object' => 'acme.tag', 'values' => ['name' => '{{ name.tag }}']],
        ],
    ];

    private const array TAG_NAME = [
        'trigger' => ['on' => 'called', 'input' => [['name' => 'sku', 'type' => 'text', 'required' => true]]],
        'steps' => [['key' => 'back', 'type' => 'return', 'values' => ['tag' => 'restock {{ $trigger.input.sku }}']]],
    ];

    public function testInstallsThemAsDraftsNamedAsHere(): void
    {
        $report = $this->install();

        self::assertSame(['Restock', 'Tag name'], $report->workflows);
        $restock = $this->workflow('Restock');
        self::assertNull($this->manager()->activeVersion($restock));
        $source = (string) $this->manager()->draft($restock)?->getSource();
        self::assertStringContainsString('object: product', $source);
        self::assertStringContainsString("workflow: 'Tag name'", $source);
        self::assertSame('A product with no seats left gets a tag.', $restock->getDescription());
    }

    public function testAnInstallTouchesNoWorkflowOfThisInstance(): void
    {
        $this->manager()->create('Restock', null, "trigger: { on: manual, object: x, label: Go }\nsteps: [{ key: a, type: refuse, message: no }]");

        $this->assertInstallRefused($this->package(), 'workflows/restock.yaml: spec.name: This instance already has a workflow "Restock".');
    }

    public function testADefinitionTheAppRefusesRefusesTheInstall(): void
    {
        $package = $this->package(['workflows/restock.yaml' => static fn (array $spec): array => ['definition' => ['trigger' => self::RESTOCK['trigger'], 'steps' => [['key' => 'w', 'type' => 'wait']]]] + $spec]);

        $this->assertInstallRefused($package, 'workflows/restock.yaml: spec.definition: steps.0: A wait takes for (a duration) or until (a date), one of them.');
    }

    public function testExportsThemWithTheirIds(): void
    {
        $this->install();

        $package = self::getContainer()->get(PackageExporter::class)->export(new ExportPackageCommand('acme/shop', '1.1.0', ['category', 'product', 'tag'], ['catalogue'], workflows: ['Restock', 'Tag name']));

        $workflows = $package->documentsOf(DocumentKind::Workflow);
        self::assertSame(['acme.workflow.restock', 'acme.workflow.tag_name'], array_map(static fn (Document $document): string => $document->id, $workflows));
        self::assertSame('workflows/restock.yaml', $workflows[0]->path);
        $spec = $workflows[0]->spec;
        self::assertSame(['acme.product', 'acme.workflow.tag_name', 'acme.tag'], [self::at($spec, 'definition.trigger.object'), self::at($spec, 'definition.steps.1.workflow'), self::at($spec, 'definition.steps.2.object')]);
    }

    public function testAnExportNamesOnlyWhatItCarries(): void
    {
        $this->install();
        $exporter = self::getContainer()->get(PackageExporter::class);
        $export = static function (string $workflow) use ($exporter): string {
            try {
                $exporter->export(new ExportPackageCommand('acme/other', '1.0.0', [], workflows: [$workflow]));
            } catch (InvalidExport $e) {
                return $e->getMessage();
            }

            return '';
        };

        self::assertSame('There is no workflow "Nothing".', $export('Nothing'));
        self::assertSame('Workflow "Restock" belongs to the installed package acme/shop: export it with that one. A package requiring acme/shop can call it.', $export('Restock'));
        $this->manager()->create('Mine', null, "trigger: { on: manual, object: product, label: Go }\nsteps: [{ key: a, type: refuse, message: no }]");
        // Its object is the installed package's: the export requires it.
        $manifest = $exporter->export(new ExportPackageCommand('acme/other', '1.0.0', [], workflows: ['Mine']))->manifest;
        self::assertSame('^1.0', self::at($manifest->spec, 'requires.acme/shop'));
        $this->manager()->create('Lonely', null, "trigger: { on: manual, object: product, label: Go }\nsteps: [{ key: a, type: run_workflow, workflow: Mine }]");
        self::assertSame('Workflow "Lonely" names workflow "Mine", which is not exported: add it to the export.', $export('Lonely'));
    }

    public function testAnUpgradeWritesTheNewVersionAsADraftAndTheActiveOneRunsOn(): void
    {
        $this->install();
        $this->signInAsSuperAdmin();
        $tagName = $this->workflow('Tag name');
        $this->manager()->activate($tagName);
        $active = $this->manager()->activeVersion($tagName)?->getSource();

        $this->upgrade($this->package(['workflows/tag_name.yaml' => static fn (array $spec): array => ['definition' => ['trigger' => self::TAG_NAME['trigger'], 'steps' => [['key' => 'back', 'type' => 'return', 'values' => ['tag' => 'buy {{ $trigger.input.sku }}']]]]] + $spec], '1.1.0'));

        self::assertSame($active, $this->manager()->activeVersion($tagName)?->getSource());
        self::assertStringContainsString('buy {{ $trigger.input.sku }}', (string) $this->manager()->draft($tagName)?->getSource());
    }

    public function testWhatOnlyAnAdminChangedIsKept(): void
    {
        $this->install();
        $mine = $this->changeRestockHere();

        // The same definition written another way: nothing changed, nothing in the way.
        $this->upgrade($this->package(['workflows/restock.yaml' => static fn (array $spec): array => ['definition' => array_reverse(self::RESTOCK, true)] + $spec], '1.0.1'));

        self::assertSame($mine, $this->manager()->draft($this->workflow('Restock'))?->getSource());
    }

    public function testWhatBothChangedIsAConflict(): void
    {
        $this->install();
        $this->changeRestockHere();
        $changed = $this->package(['workflows/restock.yaml' => static fn (array $spec): array => ['definition' => ['steps' => [self::RESTOCK['steps'][0]]] + self::RESTOCK] + $spec], '1.1.0');

        try {
            $this->upgrade($changed);
            self::fail('A conflict.');
        } catch (UpgradeRefused $e) {
            self::assertSame(['workflows/restock.yaml: change workflow acme.workflow.restock, changed here too'], $e->reasons);
        }
        $this->upgrade($changed, Strategy::Overwrite);

        self::assertStringNotContainsString('more', (string) $this->manager()->draft($this->workflow('Restock'))?->getSource());
    }

    public function testAWorkflowTheNewVersionRemovesStopsAndStaysHere(): void
    {
        $this->install();
        $this->signInAsSuperAdmin();
        $this->manager()->activate($this->workflow('Tag name'));
        $restock = $this->workflow('Restock');
        $this->manager()->activate($restock);

        $this->upgrade($this->package(['workflows/restock.yaml' => null], '2.0.0'));

        self::assertNull($this->manager()->activeVersion($restock));
        self::assertNotNull(self::getContainer()->get(WorkflowRepository::class)->findByName('Restock'));
        $owned = array_map(static fn ($item): string => $item->getItemId(), array_filter(self::getContainer()->get(PackageItemRepository::class)->itemsOf('acme/shop'), static fn ($item): bool => ItemKind::Workflow === $item->getKind()));
        self::assertSame(['acme.workflow.tag_name'], array_values($owned));
    }

    public function testANewWorkflowIsADraftAndItsNumberMustFit(): void
    {
        $this->install();
        $more = static fn (Package $package): Package => new Package($package->manifest, [...$package->documents, new Document(DocumentKind::Workflow, 'acme.workflow.notify', ['name' => 'Notify', 'definition' => self::TAG_NAME], 'workflows/notify.yaml')], $package->directory);

        $this->assertUpgradeRefused($more($this->package([], '1.0.1')), 'workflows/notify.yaml: workflow acme.workflow.notify: an addition needs a new minor version, 1.0.1 is not one.');
        $this->assertUpgradeRefused($this->package(['workflows/restock.yaml' => static fn (array $spec): array => ['definition' => ['steps' => [self::RESTOCK['steps'][0]]] + self::RESTOCK] + $spec], '1.0.1'), 'workflows/restock.yaml: workflow acme.workflow.restock: a change of definition needs a new minor version, 1.0.1 is not one.');
        $notify = $this->manager()->create('Notify', null, "trigger: { on: called }\nsteps: [{ key: r, type: return }]");
        $this->assertUpgradeRefused($more($this->package([], '1.1.0')), 'workflows/notify.yaml: workflow acme.workflow.notify: this instance already has a workflow "Notify".');

        $this->manager()->delete($notify);
        $this->upgrade($more($this->package([], '1.1.0')));
        self::assertNotNull($this->manager()->draft($this->workflow('Notify')));
    }

    /**
     * The example package with its two workflows, some documents changed (a closure) or removed
     * (null); its seeds left out of a new version.
     *
     * @param array<string, (\Closure(array<string, mixed>): array<string, mixed>)|null> $changes
     */
    private function package(array $changes = [], ?string $version = null): Package
    {
        $fixture = ExamplePackage::package();
        $documents = [
            ...array_filter($fixture->documents, static fn (Document $document): bool => null === $version || DocumentKind::Seed !== $document->kind),
            new Document(DocumentKind::Workflow, 'acme.workflow.restock', ['name' => 'Restock', 'description' => 'A product with no seats left gets a tag.', 'definition' => self::RESTOCK], 'workflows/restock.yaml'),
            new Document(DocumentKind::Workflow, 'acme.workflow.tag_name', ['name' => 'Tag name', 'definition' => self::TAG_NAME], 'workflows/tag_name.yaml'),
        ];
        $changed = [];
        foreach ($documents as $document) {
            if (\array_key_exists($document->path, $changes) && null === $changes[$document->path]) {
                continue;
            }
            $change = $changes[$document->path] ?? null;
            $changed[] = null === $change ? $document : new Document($document->kind, $document->id, $change($document->spec), $document->path);
        }
        $spec = $fixture->manifest->spec;
        $spec['resources'] = [...(\is_array($spec['resources'] ?? null) ? $spec['resources'] : []), 'workflows/*.yaml'];
        if (null !== $version) {
            $spec['version'] = $version;
        }

        return new Package(new Document($fixture->manifest->kind, $fixture->manifest->id, $spec, $fixture->manifest->path), $changed, $fixture->directory);
    }

    private function install(): \App\Module\Package\Application\InstallReport
    {
        return self::getContainer()->get(PackageInstaller::class)->install($this->package(), ExamplePackage::DIRECTORY);
    }

    private function upgrade(Package $package, ?Strategy $strategy = null): void
    {
        self::getContainer()->get(PackageUpgrader::class)->upgrade($package, ExamplePackage::DIRECTORY, $strategy);
    }

    private function assertInstallRefused(Package $package, string $reason): void
    {
        try {
            self::getContainer()->get(PackageInstaller::class)->install($package, ExamplePackage::DIRECTORY);
            self::fail(\sprintf('Refused: %s', $reason));
        } catch (InstallRefused $e) {
            self::assertContains($reason, $e->reasons, implode("\n", $e->reasons));
        }
    }

    private function assertUpgradeRefused(Package $package, string $reason): void
    {
        try {
            $this->upgrade($package);
            self::fail(\sprintf('Refused: %s', $reason));
        } catch (UpgradeRefused $e) {
            self::assertContains($reason, $e->reasons, implode("\n", $e->reasons));
        }
    }

    private function manager(): WorkflowManager
    {
        return self::getContainer()->get(WorkflowManager::class);
    }

    private function workflow(string $name): Workflow
    {
        return self::getContainer()->get(WorkflowRepository::class)->findByName($name) ?? self::fail(\sprintf('No workflow "%s".', $name));
    }

    /**
     * The value at a dotted path of nested arrays; null where there is none.
     *
     * @param array<mixed> $data
     */
    private static function at(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * An admin's change to the draft of Restock: its source now.
     */
    private function changeRestockHere(): ?string
    {
        $restock = $this->workflow('Restock');
        $this->manager()->saveDraft($restock, (string) $this->manager()->draft($restock)?->getSource()."  - { key: more, type: filter, when: { field: \$trigger.record.sku, op: not_empty } }\n");

        return $this->manager()->draft($restock)?->getSource();
    }

    /**
     * Activating needs someone signed in: whose permissions the steps use.
     */
    private function signInAsSuperAdmin(): void
    {
        $container = self::getContainer();
        $container->get(UserManagerInterface::class)->createUser('admin@example.com', 'correct horse battery staple', 'Ada', 'Lovelace');
        $container->get(PermissionSynchronizer::class)->syncAll();
        $container->get(RbacManagerInterface::class)->assign('admin@example.com', Permissions::SUPER_ADMIN_ROLE);
        $user = $container->get(UserProvider::class)->loadUserByIdentifier('admin@example.com');
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
