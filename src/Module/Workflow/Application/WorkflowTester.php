<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application;

use App\Module\Access\Contract\AccessCheckerInterface;
use App\Module\Access\Contract\RecordAction;
use App\Module\Metadata\Contract\MetadataRegistryInterface;
use App\Module\Record\Contract\Dto\RecordLabel;
use App\Module\Record\Contract\RecordLookupInterface;
use App\Module\Workflow\Application\Definition\Definition;
use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Definition\DefinitionValidator;
use App\Module\Workflow\Application\Engine\RunLog;
use App\Module\Workflow\Application\Engine\WorkflowEngine;
use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;

/**
 * The test of a draft on a record, from either editor: checked as an activation would, then run
 * for real and rolled back (WorkflowEngine::dryRun()). It shows the record's values: only on the
 * records whoever tests it may read.
 */
final class WorkflowTester
{
    public function __construct(
        private readonly DefinitionValidator $validator,
        private readonly WorkflowEngine $engine,
        private readonly AccessCheckerInterface $access,
        private readonly RecordLookupInterface $lookup,
        private readonly MetadataRegistryInterface $metadata,
    ) {
    }

    /**
     * The records to test a definition on: the latest ones of its trigger's object, by label;
     * none for whoever may not read them.
     *
     * @return list<RecordLabel>
     */
    public function records(?Definition $definition): array
    {
        $object = $definition?->trigger->object;
        if (null === $object || !$this->metadata->hasObject($object) || !$this->access->canRecords($object, RecordAction::Read)) {
            return [];
        }

        return $this->lookup->search($object, '', 50);
    }

    /**
     * @throws InvalidWorkflowDefinition the source does not check
     * @throws TestRefused               no record, or one the tester may not read
     */
    public function test(string $name, string $source, ?string $recordId): RunLog
    {
        $definition = (new DefinitionParser())->parse($source);
        $this->validator->validate($definition);
        if (!$this->access->canRecords($definition->trigger->object, RecordAction::Read)) {
            throw new TestRefused('cannot_read', $definition->trigger->object);
        }
        if (null === $recordId || '' === $recordId) {
            throw new TestRefused('choose', $definition->trigger->object);
        }

        try {
            return $this->engine->dryRun($definition, $name, $recordId);
        } catch (\InvalidArgumentException) {
            throw new TestRefused('choose', $definition->trigger->object);
        }
    }
}
