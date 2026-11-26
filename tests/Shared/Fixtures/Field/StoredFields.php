<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Fixtures\Field;

use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\FieldExpression;
use Foresi\Shared\Contract\Field\Fields;

/**
 * Fields held in memory for a test: the given definitions of one record kind and the
 * values each record holds as they are given, even those no field source would store,
 * such as a list with gaps or a date as a text. A write refuses with the given message
 * when one is given, as a field source refuses a value its kind does not take. The ids
 * that values() is asked for are kept in $loads when one is given.
 */
final readonly class StoredFields implements Fields
{
    /**
     * @param list<FieldDefinition>                $definitions
     * @param array<string, array<string, mixed>>  $values      by record id, then by field key
     * @param \ArrayObject<int, list<string>>|null $loads       the ids of each call of values()
     */
    public function __construct(private array $definitions, private array $values = [], private ?string $refusal = null, private ?\ArrayObject $loads = null)
    {
    }

    public function definitions(string $record): array
    {
        return array_values(array_filter($this->definitions, static fn (FieldDefinition $definition): bool => $definition->record === $record));
    }

    public function definition(string $record, string $key): ?FieldDefinition
    {
        foreach ($this->definitions($record) as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }

    public function values(string $record, array $ids): array
    {
        $this->loads?->append($ids);

        return array_intersect_key($this->values, array_flip($ids));
    }

    public function value(string $record, string $id, string $key): mixed
    {
        return $this->values[$id][$key] ?? null;
    }

    public function write(string $record, string $id, array $values): void
    {
        if (null !== $this->refusal) {
            throw new \InvalidArgumentException($this->refusal);
        }
    }

    public function expression(string $record, string $key, string $root, ?string $alias = null): FieldExpression
    {
        throw new \LogicException('Stored fields are not queried.');
    }

    public function searched(string $record, string $root, string $parameter): array
    {
        return [];
    }
}
