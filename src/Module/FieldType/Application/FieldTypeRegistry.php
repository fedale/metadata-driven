<?php

declare(strict_types=1);

namespace App\Module\FieldType\Application;

use App\Module\FieldType\Contract\Exception\UnknownFieldType;
use App\Module\FieldType\Contract\FieldTypeInterface;
use App\Module\FieldType\Contract\FieldTypeRegistryInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class FieldTypeRegistry implements FieldTypeRegistryInterface
{
    /** @var array<string, FieldTypeInterface> */
    private array $types = [];

    /**
     * @param iterable<FieldTypeInterface> $types
     */
    public function __construct(
        #[AutowireIterator('app.field_type')]
        iterable $types,
    ) {
        foreach ($types as $type) {
            if (isset($this->types[$type->name()])) {
                throw new \LogicException(\sprintf('Field type "%s" is registered twice.', $type->name()));
            }
            $this->types[$type->name()] = $type;
        }
        ksort($this->types);
    }

    public function get(string $name): FieldTypeInterface
    {
        return $this->types[$name] ?? throw UnknownFieldType::named($name);
    }

    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    public function all(): array
    {
        return $this->types;
    }
}
