<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application;

use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Definition\DefinitionValidator;
use App\Module\Workflow\Application\Engine\ActiveWorkflows;
use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;
use App\Module\Workflow\Domain\Model\VersionStatus;
use App\Module\Workflow\Domain\Model\Workflow;
use App\Module\Workflow\Domain\Model\WorkflowVersion;
use App\Module\Workflow\Domain\Repository\WorkflowRepository;
use App\Shared\Transaction\TransactionRunner;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * The workflows of the workspace, as the admin edits them: a draft written in YAML, activated
 * once it is checked (DefinitionValidator) by whoever activates it, whose right to give the roles
 * its steps act as is checked again at each run. Editing an active workflow makes a draft while
 * the active version keeps running.
 */
final class WorkflowManager
{
    private const string NAME = '/^\S(.{0,98}\S)?$/u';

    public function __construct(
        private readonly WorkflowRepository $workflows,
        private readonly DefinitionValidator $validator,
        private readonly ActiveWorkflows $active,
        private readonly TransactionRunner $transactions,
        private readonly Security $security,
    ) {
    }

    /**
     * A new workflow, its definition a draft (version 1), not running yet.
     *
     * @throws InvalidWorkflowDefinition a name missing or taken
     */
    public function create(string $name, ?string $description, string $source): Workflow
    {
        $name = trim($name);
        $this->assertName($name, null);
        $now = new \DateTimeImmutable();
        $workflow = new Workflow(Uuid::v7(), $name, self::blankToNull($description), $this->workflows->nextPosition(), $this->security->getUser()?->getUserIdentifier(), $now);
        $this->workflows->save($workflow, new WorkflowVersion(Uuid::v7(), $workflow, 1, VersionStatus::Draft, $source, null, $now));

        return $workflow;
    }

    /**
     * @throws InvalidWorkflowDefinition a name missing or taken
     */
    public function describe(Workflow $workflow, string $name, ?string $description): void
    {
        $name = trim($name);
        $this->assertName($name, $workflow);
        $workflow->describe($name, self::blankToNull($description), new \DateTimeImmutable());
        $this->workflows->save($workflow);
    }

    /**
     * Writes the draft, made from the source when there is none.
     */
    public function saveDraft(Workflow $workflow, string $source): WorkflowVersion
    {
        $draft = $this->draft($workflow);
        if (null === $draft) {
            $draft = new WorkflowVersion(Uuid::v7(), $workflow, $this->nextNumber($workflow), VersionStatus::Draft, $source, null, new \DateTimeImmutable());
        } else {
            $draft->rewrite($source);
        }
        $this->workflows->save($draft);

        return $draft;
    }

    /**
     * Checks the draft and makes it the active version; the active one, if any, is archived.
     *
     * @throws InvalidWorkflowDefinition what the draft has wrong
     * @throws \LogicException           no draft, or no one signed in
     */
    public function activate(Workflow $workflow): WorkflowVersion
    {
        $draft = $this->draft($workflow) ?? throw new \LogicException('There is no draft to activate.');
        $activator = $this->security->getUser()?->getUserIdentifier() ?? throw new \LogicException('A workflow is activated by someone signed in: whose rights its steps may use.');
        $definition = (new DefinitionParser())->parse($draft->getSource());
        $this->validator->validate($definition);

        $this->transactions->run(function () use ($workflow, $draft, $definition, $activator): void {
            $active = $this->activeVersion($workflow);
            $active?->archive();
            $draft->activate($definition->toArray(), $activator, new \DateTimeImmutable());
            $this->workflows->save(...array_filter([$active, $draft]));
        });
        $this->active->forget();

        return $draft;
    }

    /**
     * Stops the active version: it runs no more, until a draft is activated.
     */
    public function deactivate(Workflow $workflow): void
    {
        $active = $this->activeVersion($workflow);
        if (null === $active) {
            return;
        }
        $active->deactivate();
        $this->workflows->save($active);
        $this->active->forget();
    }

    /**
     * Makes the draft from an earlier version's source: a way back.
     */
    public function useAsDraft(WorkflowVersion $version): WorkflowVersion
    {
        return $this->saveDraft($version->getWorkflow(), $version->getSource());
    }

    /**
     * The order the workflows of one event run in.
     *
     * @param list<Workflow> $ordered
     */
    public function reorder(array $ordered): void
    {
        foreach ($ordered as $position => $workflow) {
            $workflow->moveTo($position);
        }
        $this->workflows->save(...$ordered);
        $this->active->forget();
    }

    /**
     * Deletes the workflow, its versions and its runs.
     */
    public function delete(Workflow $workflow): void
    {
        $this->workflows->remove($workflow);
        $this->active->forget();
    }

    public function draft(Workflow $workflow): ?WorkflowVersion
    {
        return $this->versionIn($workflow, VersionStatus::Draft);
    }

    public function activeVersion(Workflow $workflow): ?WorkflowVersion
    {
        return $this->versionIn($workflow, VersionStatus::Active);
    }

    private function versionIn(Workflow $workflow, VersionStatus $status): ?WorkflowVersion
    {
        foreach ($this->workflows->versionsOf($workflow) as $version) {
            if ($status === $version->getStatus()) {
                return $version;
            }
        }

        return null;
    }

    private function nextNumber(Workflow $workflow): int
    {
        return 1 + max(0, ...array_map(static fn (WorkflowVersion $version): int => $version->getNumber(), $this->workflows->versionsOf($workflow)));
    }

    /**
     * @throws InvalidWorkflowDefinition
     */
    private function assertName(string $name, ?Workflow $workflow): void
    {
        if (1 !== preg_match(self::NAME, $name)) {
            throw new InvalidWorkflowDefinition(['name' => ['Required: at most 100 characters.']]);
        }
        $taken = $this->workflows->findByName($name);
        if (null !== $taken && $taken !== $workflow) {
            throw new InvalidWorkflowDefinition(['name' => [\sprintf('There is a workflow "%s" already.', $name)]]);
        }
    }

    private static function blankToNull(?string $text): ?string
    {
        return null === $text || '' === trim($text) ? null : trim($text);
    }
}
