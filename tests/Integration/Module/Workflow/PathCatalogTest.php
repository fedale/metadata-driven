<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Workflow;

use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\PathCatalog;
use App\Tests\Support\CreatesRelatedObjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PathCatalogTest extends KernelTestCase
{
    use CreatesRelatedObjects;

    public function testOffersThePathsTheDefinitionCanRead(): void
    {
        self::createRelatedObjects(self::getContainer());
        $paths = self::getContainer()->get(PathCatalog::class)->paths((new DefinitionParser())->parse(<<<'YAML'
            trigger: { on: updated, object: pet, when: after }
            steps:
              - key: check
                type: if
                branches:
                  - when: { field: $trigger.record.name, op: empty }
                    steps: [{ key: visit, type: upsert_record, object: visit, match: notes, values: { notes: x } }]
              - { key: ok, type: filter, when: { field: $trigger.record.name, op: not_empty } }
              - { key: no, type: refuse, message: no }
            YAML));

        self::assertSame(['$trigger.object', '$trigger.action', '$trigger.record.id', '$trigger.record.name', '$trigger.record.owner', '$trigger.before.id', '$trigger.before.name', '$trigger.before.owner', '$trigger.changed'], $paths['trigger']);
        self::assertContains('$today', $paths['context']);
        self::assertSame(['check' => ['check.branch'], 'visit' => ['visit.id', 'visit.notes', 'visit.pet', 'visit.created'], 'ok' => ['ok.passed']], $paths['steps']);
    }

    public function testAManualTriggerOffersTheInputsOfItsForm(): void
    {
        self::createRelatedObjects(self::getContainer());
        $paths = self::getContainer()->get(PathCatalog::class)->paths((new DefinitionParser())->parse(<<<'YAML'
            trigger: { on: manual, object: pet, label: Rename, form: [{ name: name, label: Name, type: text }] }
            steps: [{ key: no, type: refuse, message: no }]
            YAML));

        self::assertSame(['$trigger.object', '$trigger.action', '$trigger.record.id', '$trigger.record.name', '$trigger.record.owner', '$trigger.input.name'], $paths['trigger']);
    }

    public function testASearchAndAnIteratorOfferTheirRecords(): void
    {
        self::createRelatedObjects(self::getContainer());
        $paths = self::getContainer()->get(PathCatalog::class)->paths((new DefinitionParser())->parse(<<<'YAML'
            trigger: { on: manual, object: person, label: Pets }
            steps:
              - { key: pets, type: search_records, object: pet }
              - { key: each, type: iterator, items: '{{ pets.records }}', steps: [{ key: no, type: refuse, message: no }] }
            YAML));

        self::assertSame([
            'pets' => ['pets.records', 'pets.count', 'pets.total', 'pets.first.id', 'pets.first.name', 'pets.first.owner'],
            'each' => ['each.item', 'each.item.id', 'each.item.name', 'each.item.owner', 'each.index', 'each.count'],
        ], $paths['steps']);
    }

    public function testWithoutADefinitionTheRootsOnly(): void
    {
        $paths = self::getContainer()->get(PathCatalog::class)->paths(null);

        self::assertSame(['$trigger.object', '$trigger.action', '$trigger.record.id', '$trigger.changed'], $paths['trigger']);
        self::assertSame([], $paths['steps']);
    }
}
