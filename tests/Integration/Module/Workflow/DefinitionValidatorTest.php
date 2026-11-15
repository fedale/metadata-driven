<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Workflow;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\WorkspaceRolesInterface;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Definition\DefinitionValidator;
use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;
use App\Tests\Support\CreatesRelatedObjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a definition names, checked when it is activated: objects, fields, the paths each step
 * may read, the roles the person activating may give.
 */
final class DefinitionValidatorTest extends KernelTestCase
{
    use CreatesRelatedObjects;

    protected function setUp(): void
    {
        self::createRelatedObjects(self::getContainer());
    }

    public function testAcceptsWhatTheWorkspaceHas(): void
    {
        $this->validate(<<<'YAML'
            trigger: { on: updated, object: person, when: after, fields: [city] }
            steps:
              - { key: moved, type: filter, when: { field: $trigger.before.city, op: neq, value: '{{ $trigger.record.city }}' } }
              - key: note
                type: create_record
                object: pet
                values: { name: 'Moved to {{ $trigger.record.city }}', owner: '{{ $trigger.record.id }}' }
                act_as: ROLE_CLERK
              - { key: again, type: update_record, object: pet, id: '{{ note.id }}', values: { name: '{{ $last.name }}' } }
            YAML);
        $this->addToAssertionCount(1);
    }

    public function testReportsWhatItNamesAndCannotBe(): void
    {
        $errors = $this->errors(<<<'YAML'
            trigger: { on: created, object: person, when: before }
            steps:
              - key: check
                type: if
                branches:
                  - when: { field: $trigger.record.nickname, op: empty }
                    steps: [{ key: inner, type: set_values, values: { age: 3 } }]
                  - steps: [{ key: other, type: refuse, message: '{{ inner.id }}' }]
              - { key: ghost, type: create_record, object: dragon, values: { name: x } }
              - { key: up, type: upsert_record, object: person, match: city, values: { full_name: '{{ $trigger.before.full_name }}' } }
              - { key: boss, type: delete_record, object: person, id: '{{ nowhere.id }}', act_as: ROLE_BOSS }
              - { key: who, type: delete_record, object: person, id: '{{ $trigger.who }}', act_as: ROLE_NOBODY }
            YAML);

        self::assertSame([
            'steps.0.branches.0.when.field' => ['Object "person" has no field "nickname".'],
            'steps.0.branches.0.steps.0.values.age' => ['Object "person" has no field "age".'],
            'steps.0.branches.1.steps.0.message' => ['"inner" is not run before this point on every way here: its output cannot be read.'],
            'steps.1.object' => ['There is no object "dragon".'],
            'steps.2.values.full_name' => ['$trigger.before is the record as it was before an update: not there for this trigger.'],
            'steps.2.match' => ['A unique field of "person": the one that tells the record to update.'],
            'steps.2.values' => ['The value of "city", the field it matches on, is required.'],
            'steps.3.id' => ['"nowhere" is no step and no root of the chain ($trigger, $user, $workspace, $last, $now, $today).'],
            'steps.3.act_as' => ['You cannot give the role ROLE_BOSS: a workflow you activate may not act as it.'],
            'steps.4.id' => ['$trigger holds object, action, record, before and changed: not "who".'],
            'steps.4.act_as' => ['"ROLE_NOBODY" is no role of this workspace.'],
        ], $errors);
    }

    public function testAStepAsWhoeverTriggeredItDoesNoMoreThanItsActivatorMay(): void
    {
        $errors = $this->errors(<<<'YAML'
            trigger: { on: updated, object: person, when: after }
            steps:
              - { key: gone, type: delete_record, object: person, id: '{{ $trigger.record.id }}' }
              - { key: as_role, type: delete_record, object: person, id: '{{ $trigger.record.id }}', act_as: ROLE_CLERK }
            YAML);

        // The step acting as the clerk's role is checked against that role instead.
        self::assertSame(['steps.0.type' => ['You do not hold record.person.delete: a step acting as whoever triggered the event may not do what you cannot. Give it an act_as, a role you may give.']], $errors);
    }

    public function testSetValuesChangesOnlyWhatItsActivatorMayWrite(): void
    {
        $errors = $this->errors(<<<'YAML'
            trigger: { on: deleted, object: person, when: before }
            steps: [{ key: city, type: set_values, values: { city: Torino } }]
            YAML);

        self::assertSame(['steps.0.type' => ['set_values changes the values being written: a delete writes none.']], $errors);
    }

    private function validate(string $yaml): void
    {
        $access = $this->createStub(AccessCheckerInterface::class);
        // The person activating holds ROLE_CLERK, not ROLE_BOSS.
        $access->method('canGrant')->willReturnCallback(static fn (string $role): bool => 'ROLE_CLERK' === $role);
        // ...and every permission but deleting people.
        $access->method('holds')->willReturnCallback(static fn (string $item): bool => 'record.person.delete' !== $item);
        $roles = $this->createStub(WorkspaceRolesInterface::class);
        $roles->method('roles')->willReturn(['ROLE_CLERK' => null, 'ROLE_BOSS' => null]);

        (new DefinitionValidator(self::getContainer()->get(MetadataRegistryInterface::class), $access, $roles))
            ->validate((new DefinitionParser())->parse($yaml));
    }

    /**
     * @return array<string, list<string>>
     */
    private function errors(string $yaml): array
    {
        try {
            $this->validate($yaml);
        } catch (InvalidWorkflowDefinition $e) {
            return $e->errors;
        }
        self::fail('Refused.');
    }
}
