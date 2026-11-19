<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application;

use App\Module\Workflow\Application\Definition\DefinitionParser;
use App\Module\Workflow\Application\Definition\WorkflowYaml;
use App\Module\Workflow\Contract\Dto\WorkflowSource;
use App\Module\Workflow\Contract\Exception\InvalidWorkflowSource;
use App\Module\Workflow\Contract\PackagedWorkflowsInterface;
use App\Module\Workflow\Domain\Exception\InvalidWorkflowDefinition;
use App\Module\Workflow\Domain\Model\Workflow;
use App\Module\Workflow\Domain\Model\WorkflowVersion;
use App\Module\Workflow\Domain\Repository\WorkflowRepository;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The workflows as packages carry them (the Package module): through WorkflowManager, as the
 * admin's pages, never activated.
 */
final class PackagedWorkflows implements PackagedWorkflowsInterface
{
    public function __construct(
        private readonly WorkflowRepository $workflows,
        private readonly WorkflowManager $manager,
    ) {
    }

    public function all(): array
    {
        $all = [];
        foreach ($this->workflows->all() as $workflow) {
            $id = $workflow->getId()->toRfc4122();
            $all[$id] = new WorkflowSource($id, $workflow->getName(), $workflow->getDescription(), $this->current($workflow), null !== $this->manager->activeVersion($workflow));
        }

        return $all;
    }

    public function canonical(array $definition): array
    {
        try {
            return (new DefinitionParser())->fromArray($definition)->toArray();
        } catch (InvalidWorkflowDefinition $e) {
            throw new InvalidWorkflowSource($e->errors);
        }
    }

    public function create(string $name, ?string $description, array $definition): string
    {
        $source = self::source($this->canonical($definition));
        try {
            return $this->manager->create($name, $description, $source)->getId()->toRfc4122();
        } catch (InvalidWorkflowDefinition $e) {
            throw new InvalidWorkflowSource($e->errors);
        }
    }

    public function draft(string $id, string $name, ?string $description, array $definition): void
    {
        $source = self::source($this->canonical($definition));
        $workflow = $this->find($id);
        try {
            if ($workflow->getName() !== $name || $workflow->getDescription() !== $description) {
                $this->manager->describe($workflow, $name, $description);
            }
        } catch (InvalidWorkflowDefinition $e) {
            throw new InvalidWorkflowSource($e->errors);
        }
        $this->manager->saveDraft($workflow, $source);
    }

    public function deactivate(string $id): void
    {
        $this->manager->deactivate($this->find($id));
    }

    /**
     * Its draft, else its active version, else its latest: what an admin would edit next.
     *
     * @return array<string, mixed>
     */
    private function current(Workflow $workflow): array
    {
        $versions = $this->workflows->versionsOf($workflow);
        usort($versions, static fn (WorkflowVersion $a, WorkflowVersion $b): int => $b->getNumber() <=> $a->getNumber());
        $version = $this->manager->draft($workflow) ?? $this->manager->activeVersion($workflow) ?? $versions[0] ?? null;
        if (null === $version) {
            return [];
        }
        try {
            return (new DefinitionParser())->parse($version->getSource())->toArray();
        } catch (InvalidWorkflowDefinition) {
            // A draft being written: as it is, which no package's definition equals.
            try {
                $raw = WorkflowYaml::parse($version->getSource());
            } catch (ParseException) {
                return ['source' => $version->getSource()];
            }

            return \is_array($raw) ? array_combine(array_map(strval(...), array_keys($raw)), array_values($raw)) : ['source' => $version->getSource()];
        }
    }

    private function find(string $id): Workflow
    {
        return (Uuid::isValid($id) ? $this->workflows->find(Uuid::fromString($id)) : null) ?? throw new \InvalidArgumentException(\sprintf('There is no workflow %s.', $id));
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function source(array $definition): string
    {
        return Yaml::dump($definition, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
}
