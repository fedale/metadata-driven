<?php

declare(strict_types=1);

namespace App\Module\Metadata\Application\Registry;

use App\Module\Metadata\Contract\Dto\FormDefinitionDto;
use App\Module\Metadata\Contract\Dto\FormNodeDto;
use App\Module\Metadata\Contract\Dto\FormNodeMode;
use App\Module\Metadata\Contract\Dto\ServiceFieldDto;
use App\Module\Metadata\Domain\Model\FormDefinition;
use App\Module\Metadata\Domain\Repository\FormDefinitionRepository;

/**
 * Builds the composite forms of the snapshot from storage, without any caching.
 */
final class FormSnapshotLoader
{
    public function __construct(
        private readonly FormDefinitionRepository $forms,
    ) {
    }

    /**
     * @return array<string, FormDefinitionDto> keyed by form name
     */
    public function load(): array
    {
        $snapshot = [];
        foreach ($this->forms->findAll() as $form) {
            $snapshot[$form->getName()] = $this->mapForm($form);
        }

        return $snapshot;
    }

    private function mapForm(FormDefinition $form): FormDefinitionDto
    {
        return new FormDefinitionDto(
            $form->getId()->toRfc4122(),
            $form->getName(),
            $form->getLabel(),
            $this->mapNode($form->getDefinition()),
            $form->getSource(),
        );
    }

    /**
     * @param array<mixed> $node as compiled by FormDefinitionCompiler
     */
    private function mapNode(array $node): FormNodeDto
    {
        $service = [];
        foreach (\is_array($node['service'] ?? null) ? $node['service'] : [] as $field) {
            if (\is_array($field)) {
                /** @var array<string, mixed> $settings compiled: a map */
                $settings = \is_array($field['settings'] ?? null) ? $field['settings'] : [];
                $service[] = new ServiceFieldDto(self::string($field, 'name'), self::string($field, 'type'), self::string($field, 'label'), true === ($field['required'] ?? false), $settings);
            }
        }
        $fields = \is_array($node['fields'] ?? null) ? array_values(array_filter($node['fields'], is_string(...))) : null;

        return new FormNodeDto(
            self::string($node, 'name'),
            self::string($node, 'label'),
            self::string($node, 'object'),
            \is_string($node['via'] ?? null) ? $node['via'] : null,
            FormNodeMode::tryFrom(self::string($node, 'mode')) ?? FormNodeMode::One,
            \is_int($node['min'] ?? null) ? $node['min'] : 0,
            \is_int($node['max'] ?? null) ? $node['max'] : null,
            $fields,
            $service,
            array_map(fn (mixed $section): FormNodeDto => $this->mapNode(\is_array($section) ? $section : []), \is_array($node['sections'] ?? null) ? array_values($node['sections']) : []),
            true === ($node['inline'] ?? false),
            \is_array($node['required'] ?? null) ? array_values(array_filter($node['required'], is_string(...))) : [],
            \is_string($node['layout'] ?? null) ? $node['layout'] : null,
        );
    }

    /**
     * @param array<mixed> $map
     */
    private static function string(array $map, string $key): string
    {
        return \is_string($map[$key] ?? null) ? $map[$key] : '';
    }
}
