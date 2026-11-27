<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Workflow;

use App\Module\Access\Application\PermissionSynchronizer;
use App\Module\Metadata\Contract\Exception\MetadataException;
use App\Module\Metadata\Contract\MetadataManagerInterface;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Infrastructure\Security\UserProvider;
use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Engine\ActiveWorkflows;
use App\Module\Workflow\Application\WorkflowManager;
use App\Module\Workflow\Domain\Model\VersionStatus;
use App\Module\Workflow\Domain\Model\Workflow;
use App\Module\Workflow\Domain\Repository\WorkflowRepository;
use App\Shared\Message\MessageTranslator;
use App\Tests\Support\CreatesRelatedObjects;
use Fedale\RbacBundle\Contract\RbacManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The metadata's lifecycle and the workflows naming it: archiving refused while an active
 * workflow uses the object or the field, a rename carried into the workflows.
 */
final class MetadataInWorkflowsTest extends KernelTestCase
{
    use CreatesRelatedObjects;

    private const string STAMP = <<<'YAML'
        # Stamps a pet's city from its owner.
        trigger: { on: updated, object: pet, when: after, fields: [name] }
        steps:
          - { key: owner, type: update_record, object: person, id: '{{ $trigger.record.owner }}', values: { city: 'Has {{ $trigger.record.name }}' } }
        YAML;

    private WorkflowManager $workflows;
    private MetadataManagerInterface $metadata;

    protected function setUp(): void
    {
        $container = self::getContainer();
        self::createRelatedObjects($container);
        $container->get(PermissionSynchronizer::class)->syncAll();
        $container->get(UserManagerInterface::class)->createUser('owner@example.com', 'correct horse battery staple', 'Ada', 'Lovelace');
        foreach (['person', 'pet'] as $object) {
            foreach (['read', 'create', 'update', 'delete'] as $action) {
                $container->get(RbacManagerInterface::class)->assign('owner@example.com', 'record.'.$object.'.'.$action);
            }
        }
        $user = $container->get(UserProvider::class)->loadUserByIdentifier('owner@example.com');
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $this->workflows = $container->get(WorkflowManager::class);
        $this->metadata = $container->get(MetadataManagerInterface::class);
    }

    public function testArchivingWhatAnActiveWorkflowNamesIsRefused(): void
    {
        $this->workflows->activate($this->workflows->create('Stamp', null, self::STAMP));

        foreach ([
            ['person', null, 'workflow "Stamp" (steps.0.object)'],
            ['pet', 'name', 'workflow "Stamp" (trigger.fields.0, steps.0.values.city)'],
            ['person', 'city', 'workflow "Stamp" (steps.0.values.city)'],
        ] as [$object, $field, $use]) {
            try {
                null === $field ? $this->metadata->archiveObject($object) : $this->metadata->archiveField($object, $field);
                self::fail('Refused.');
            } catch (MetadataException $e) {
                self::assertStringContainsString($use, $e->getMessage());
            }
        }
        // What it does not name is archived.
        $this->metadata->archiveField('person', 'manager');
    }

    public function testADraftDoesNotKeepAnythingFromBeingArchived(): void
    {
        $this->workflows->create('Stamp', null, self::STAMP);

        $this->metadata->archiveField('person', 'city');

        // Checked again when it is activated.
        $this->expectExceptionMessage('Object "person" has no field "city".');
        $this->workflows->activate($this->workflowNamed('Stamp'));
    }

    public function testRenamingAFieldRewritesTheWorkflowsThatNameIt(): void
    {
        $stamp = $this->workflows->create('Stamp', null, self::STAMP);
        $this->workflows->activate($stamp);
        $this->workflows->saveDraft($stamp, self::STAMP."\n  - { key: again, type: update_record, object: person, id: '{{ \$trigger.record.owner }}', values: { city: x } }\n");
        $other = $this->workflows->create('Other', null, "trigger: { on: created, object: person, when: after }\nsteps: [{ key: no, type: filter, when: { field: \$trigger.record.full_name, op: not_empty } }]");
        $this->workflows->activate($other);

        $rewritten = $this->metadata->renameField('person', 'city', 'town');
        self::assertSame(['workflow "Stamp" (draft, active)'], array_map(strval(...), $rewritten));
        // Its versions named in the language of the page.
        self::assertSame(['workflow "Stamp" (bozza, attiva)'], self::getContainer()->get(MessageTranslator::class)->transAll($rewritten, 'it'));

        $active = $this->workflows->activeVersion($stamp);
        self::assertNotNull($active);
        self::assertStringContainsString("town: 'Has {{ \$trigger.record.name }}'", $active->getSource());
        $definition = $active->getDefinition();
        self::assertNotNull($definition);
        self::assertSame(['town' => 'Has {{ $trigger.record.name }}'], (new DefinitionParser())->fromArray($definition)->steps[0]->values());
        // The compiled definition is what the source compiles to.
        self::assertEquals((new DefinitionParser())->parse($active->getSource())->toArray(), $definition);
        self::assertSame(['town' => 'x'], (new DefinitionParser())->parse((string) $this->workflows->draft($stamp)?->getSource())->steps[1]->values());
        // What runs reads the new name.
        self::assertSame(['town' => 'Has {{ $trigger.record.name }}'], self::getContainer()->get(ActiveWorkflows::class)->byName('Stamp')?->definition->steps[0]->values());
        // A workflow not naming it keeps its source as written.
        self::assertSame("trigger: { on: created, object: person, when: after }\nsteps: [{ key: no, type: filter, when: { field: \$trigger.record.full_name, op: not_empty } }]", $this->workflows->activeVersion($other)?->getSource());
    }

    public function testRenamingAnObjectRewritesTheWorkflowsButNotTheirHistory(): void
    {
        $stamp = $this->workflows->create('Stamp', null, self::STAMP);
        $this->workflows->activate($stamp);
        $this->workflows->saveDraft($stamp, str_replace('Has ', 'Owns ', self::STAMP));
        $this->workflows->activate($stamp);

        self::assertSame(['workflow "Stamp" (active)'], array_map(strval(...), $this->metadata->renameObject('pet', 'animal')));

        $versions = self::getContainer()->get(WorkflowRepository::class)->versionsOf($stamp);
        $sources = array_combine(array_map(static fn ($version): string => $version->getStatus()->value, $versions), array_map(static fn ($version): string => $version->getSource(), $versions));
        self::assertStringContainsString('object: animal', $sources[VersionStatus::Active->value]);
        // Version 1, archived, stays as it ran: comments included.
        self::assertSame(self::STAMP, $sources[VersionStatus::Archived->value]);
        self::assertSame('animal', self::getContainer()->get(ActiveWorkflows::class)->byName('Stamp')?->definition->trigger->object);
    }

    private function workflowNamed(string $name): Workflow
    {
        $workflow = self::getContainer()->get(WorkflowRepository::class)->findByName($name);
        self::assertNotNull($workflow);

        return $workflow;
    }
}
