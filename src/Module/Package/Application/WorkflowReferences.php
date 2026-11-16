<?php

declare(strict_types=1);

namespace App\Module\Package\Application;

/**
 * What a workflow's definition names that a package names by id: the object of its trigger and
 * of its steps, the workflow each run_workflow step calls. Its fields stay named by name, within
 * their object, as in a view's layout.
 */
final class WorkflowReferences
{
    /**
     * The definition with each object and each workflow called mapped: names to ids, or back.
     *
     * @param array<mixed>             $definition
     * @param \Closure(string): string $object
     * @param \Closure(string): string $workflow
     *
     * @return array<string, mixed>
     */
    public static function map(array $definition, \Closure $object, \Closure $workflow): array
    {
        $mapped = self::keyed($definition);
        if (\is_array($mapped['trigger'] ?? null) && \is_string($mapped['trigger']['object'] ?? null) && '' !== $mapped['trigger']['object']) {
            $mapped['trigger']['object'] = $object($mapped['trigger']['object']);
        }
        if (\is_array($mapped['steps'] ?? null)) {
            $mapped['steps'] = self::steps($mapped['steps'], $object, $workflow);
        }

        return $mapped;
    }

    /**
     * The objects a definition names, and the workflows it calls, as written.
     *
     * @param array<mixed> $definition
     *
     * @return array{objects: list<string>, workflows: list<string>}
     */
    public static function of(array $definition): array
    {
        $found = ['objects' => [], 'workflows' => []];
        self::map(
            $definition,
            static function (string $name) use (&$found): string {
                $found['objects'][] = $name;

                return $name;
            },
            static function (string $name) use (&$found): string {
                $found['workflows'][] = $name;

                return $name;
            },
        );

        return ['objects' => array_values(array_unique($found['objects'])), 'workflows' => array_values(array_unique($found['workflows']))];
    }

    /**
     * The definition with every object and every workflow called given a stand-in name, as the
     * app's parser takes it, and the way back: a package's ids are no names.
     *
     * @param array<mixed> $definition
     *
     * @return array{array<string, mixed>, \Closure(array<mixed>): array<string, mixed>}
     */
    public static function standIns(array $definition): array
    {
        /** @var array<string, string> $objects id => stand-in */
        $objects = [];
        /** @var array<string, string> $workflows id => stand-in */
        $workflows = [];
        $named = self::map(
            $definition,
            static function (string $id) use (&$objects): string {
                return $objects[$id] ??= 'package_object_'.(\count($objects) + 1);
            },
            static function (string $id) use (&$workflows): string {
                return $workflows[$id] ??= 'package workflow '.(\count($workflows) + 1);
            },
        );
        $objectIds = array_flip($objects);
        $workflowIds = array_flip($workflows);
        $back = static fn (array $canonical): array => self::map(
            $canonical,
            static fn (string $name): string => $objectIds[$name] ?? $name,
            static fn (string $name): string => $workflowIds[$name] ?? $name,
        );

        return [$named, $back];
    }

    /**
     * @param array<mixed>             $steps
     * @param \Closure(string): string $object
     * @param \Closure(string): string $workflow
     *
     * @return array<mixed>
     */
    private static function steps(array $steps, \Closure $object, \Closure $workflow): array
    {
        foreach ($steps as $i => $step) {
            if (!\is_array($step)) {
                continue;
            }
            if (\is_string($step['object'] ?? null)) {
                $step['object'] = $object($step['object']);
            }
            if ('run_workflow' === ($step['type'] ?? null) && \is_string($step['workflow'] ?? null)) {
                $step['workflow'] = $workflow($step['workflow']);
            }
            if (\is_array($step['branches'] ?? null)) {
                foreach ($step['branches'] as $b => $branch) {
                    if (\is_array($branch) && \is_array($branch['steps'] ?? null)) {
                        $branch['steps'] = self::steps($branch['steps'], $object, $workflow);
                        $step['branches'][$b] = $branch;
                    }
                }
            }
            if (\is_array($step['steps'] ?? null)) {
                $step['steps'] = self::steps($step['steps'], $object, $workflow);
            }
            $steps[$i] = $step;
        }

        return $steps;
    }

    /**
     * @param array<mixed> $map
     *
     * @return array<string, mixed>
     */
    private static function keyed(array $map): array
    {
        $keyed = [];
        foreach ($map as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
