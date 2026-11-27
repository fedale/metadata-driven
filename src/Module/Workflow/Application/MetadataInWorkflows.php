<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application;

use App\Module\Metadata\Contract\MetadataDependentInterface;
use App\Module\Workflow\Application\Definition\DefinitionNames;
use App\Module\Workflow\Application\Definition\WorkflowYaml;
use App\Module\Workflow\Application\Engine\ActiveWorkflows;
use App\Module\Workflow\Domain\Model\VersionStatus;
use App\Module\Workflow\Domain\Model\WorkflowVersion;
use App\Module\Workflow\Domain\Repository\WorkflowRepository;
use App\Shared\Message\Message;
use App\Shared\Message\MessageList;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The workflows of the workspace, for the metadata's lifecycle.
 *
 * Archiving: only the active versions use an object or a field. A draft, or a version that is
 * not running, is checked again when it is activated, and fails there with its reason.
 *
 * Renaming: the active version, the draft and a deactivated version (the one a stopped workflow
 * is started again from) follow the new name, source and compiled definition alike; an archived
 * version stays as it ran. A source only changes when it names the old name, and is then written
 * back from its data: its comments are lost, as a form's are.
 */
final class MetadataInWorkflows implements MetadataDependentInterface
{
    /** The versions a rename rewrites. */
    private const array FOLLOWING = [VersionStatus::Draft, VersionStatus::Active, VersionStatus::Deactivated];

    public function __construct(
        private readonly WorkflowRepository $workflows,
        private readonly ActiveWorkflows $active,
    ) {
    }

    public function usesOfObject(string $objectName): array
    {
        return $this->uses(static fn (array $reference): bool => null === $reference['field'] && $reference['object'] === $objectName);
    }

    public function usesOfField(string $objectName, string $fieldName): array
    {
        return $this->uses(static fn (array $reference): bool => $reference['object'] === $objectName && $reference['field'] === $fieldName);
    }

    public function objectRenamed(string $oldName, string $newName): array
    {
        return $this->rename(static fn (array $data): array => DefinitionNames::renameObject($data, $oldName, $newName));
    }

    public function fieldRenamed(string $objectName, string $oldName, string $newName): array
    {
        return $this->rename(static fn (array $data): array => DefinitionNames::renameField($data, $objectName, $oldName, $newName));
    }

    /**
     * @param callable(array{object: string, field: string|null, at: string}): bool $matches
     *
     * @return list<Message>
     */
    private function uses(callable $matches): array
    {
        $uses = [];
        foreach ($this->workflows->all() as $workflow) {
            foreach ($this->workflows->versionsOf($workflow) as $version) {
                $definition = $version->getDefinition();
                if (VersionStatus::Active !== $version->getStatus() || null === $definition) {
                    continue;
                }
                $places = array_column(array_values(array_filter(DefinitionNames::references($definition), $matches)), 'at');
                if ([] !== $places) {
                    $uses[] = new Message('workflow.uses', 'workflow "%workflow%" (%places%)', ['workflow' => $workflow->getName(), 'places' => implode(', ', array_unique($places))]);
                }
            }
        }

        return $uses;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $rename
     *
     * @return list<Message>
     */
    private function rename(callable $rename): array
    {
        $rewritten = [];
        foreach ($this->workflows->all() as $workflow) {
            $statuses = [];
            foreach ($this->workflows->versionsOf($workflow) as $version) {
                if (!\in_array($version->getStatus(), self::FOLLOWING, true)) {
                    continue;
                }
                $source = self::renamedSource($version, $rename);
                $definition = $version->getDefinition();
                $renamed = null === $definition ? null : $rename($definition);
                if ($source === $version->getSource() && $renamed === $definition) {
                    continue;
                }
                $version->followRename($source, $renamed);
                $this->workflows->save($version);
                $statuses[] = self::status($version->getStatus());
            }
            if ([] !== $statuses) {
                $rewritten[] = new Message('workflow.rewritten', 'workflow "%workflow%" (%versions%)', ['workflow' => $workflow->getName(), 'versions' => new MessageList($statuses, ', ')]);
            }
        }
        if ([] !== $rewritten) {
            $this->active->forget();
        }

        return $rewritten;
    }

    /**
     * The versions a rename rewrites, as the admin's page names them.
     */
    private static function status(VersionStatus $status): Message
    {
        return match ($status) {
            VersionStatus::Draft => new Message('workflow.version.draft', 'draft'),
            VersionStatus::Active => new Message('workflow.version.active', 'active'),
            default => new Message('workflow.version.deactivated', 'deactivated'),
        };
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $rename
     */
    private static function renamedSource(WorkflowVersion $version, callable $rename): string
    {
        try {
            $data = WorkflowYaml::parse($version->getSource());
        } catch (ParseException) {
            // A draft that is not YAML yet: nothing in it can be told apart as a name.
            return $version->getSource();
        }
        if (!\is_array($data)) {
            return $version->getSource();
        }
        $data = array_combine(array_map(strval(...), array_keys($data)), array_values($data));
        $renamed = $rename($data);

        // Untouched, it keeps its comments; renamed, written as the visual editor writes it.
        return $renamed === $data ? $version->getSource() : Yaml::dump($renamed, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
}
