<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Field;

use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\FieldExpression;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Shared\Contract\Field\FieldSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The custom fields of every field source (ADR 0045). A key belongs to one source;
 * the values read are remembered until the end of the request, and a write keeps
 * them current.
 */
#[AsAlias(Fields::class)]
final class FieldRegistry implements Fields, ResetInterface
{
    /** @var array<string, array<string, array{FieldDefinition, FieldSource}>> by record, then by key */
    private array $definitions = [];

    /** @var array<string, array<string, array<string, mixed>>> by record, then by id, then by key */
    private array $values = [];

    /**
     * @param iterable<FieldSource> $sources
     */
    public function __construct(
        #[AutowireIterator(FieldSource::TAG)]
        private readonly iterable $sources,
    ) {
    }

    public function definitions(string $record): array
    {
        return array_values(array_map(static fn (array $entry): FieldDefinition => $entry[0], $this->of($record)));
    }

    public function definition(string $record, string $key): ?FieldDefinition
    {
        return $this->of($record)[$key][0] ?? null;
    }

    public function values(string $record, array $ids): array
    {
        $missing = array_values(array_unique(array_filter($ids, fn (string $id): bool => !isset($this->values[$record][$id]))));
        if ([] !== $missing) {
            $sources = [];
            foreach ($this->of($record) as [, $source]) {
                $sources[spl_object_id($source)] = $source;
            }
            foreach ($missing as $id) {
                $this->values[$record][$id] = [];
            }
            foreach ($sources as $source) {
                foreach ($source->read($record, $missing) as $id => $values) {
                    $this->values[$record][$id] = [...$this->values[$record][$id] ?? [], ...$values];
                }
            }
        }

        return array_intersect_key($this->values[$record] ?? [], array_flip($ids));
    }

    public function value(string $record, string $id, string $key): mixed
    {
        if (!isset($this->values[$record][$id])) {
            $this->values($record, [$id]);
        }

        return $this->values[$record][$id][$key] ?? null;
    }

    public function write(string $record, string $id, array $values): void
    {
        $bySource = [];
        $checked = [];
        foreach ($values as $key => $value) {
            [$definition, $source] = $this->of($record)[$key] ?? throw new \InvalidArgumentException(\sprintf('The %s has no custom field "%s".', $record, $key));
            $checked[$key] = $this->checked($definition, $value);
            $bySource[spl_object_id($source)] = [$source, [...$bySource[spl_object_id($source)][1] ?? [], $key => $checked[$key]]];
        }
        foreach ($bySource as [$source, $changes]) {
            $source->write($record, $id, $changes);
        }
        if (isset($this->values[$record][$id])) {
            foreach ($checked as $key => $value) {
                if (null === $value) {
                    unset($this->values[$record][$id][$key]);
                } else {
                    $this->values[$record][$id][$key] = $value;
                }
            }
        }
    }

    public function expression(string $record, string $key, string $root, ?string $alias = null): FieldExpression
    {
        $source = $this->of($record)[$key][1] ?? throw new \InvalidArgumentException(\sprintf('The %s has no custom field "%s".', $record, $key));

        $expression = $source->expression($record, $key, $root);

        return null === $alias ? $expression : $expression->aliased($alias);
    }

    public function searched(string $record, string $root, string $parameter): array
    {
        $conditions = [];
        foreach ($this->of($record) as $key => [$definition]) {
            if ($definition->searchable) {
                $expression = $this->expression($record, $key, $root, 'cfs_'.$key);
                $conditions[] = 'EXISTS (SELECT 1 FROM '.$expression->within().' AND LOWER('.$expression->column.') LIKE :'.$parameter.' ESCAPE \'!\')';
            }
        }

        return $conditions;
    }

    public function reset(): void
    {
        $this->values = [];
    }

    /**
     * @return array<string, array{FieldDefinition, FieldSource}>
     */
    private function of(string $record): array
    {
        if (!isset($this->definitions[$record])) {
            $this->definitions[$record] = [];
            foreach ($this->sources as $source) {
                foreach ($source->definitions($record) as $definition) {
                    if (isset($this->definitions[$record][$definition->key])) {
                        throw new \LogicException(\sprintf('Two field sources declare the field "%s" of the %s.', $definition->key, $record));
                    }
                    $this->definitions[$record][$definition->key] = [$definition, $source];
                }
            }
        }

        return $this->definitions[$record];
    }

    /**
     * The value as its kind keeps it: an empty text or set is no value.
     */
    private function checked(FieldDefinition $definition, mixed $value): mixed
    {
        if (null === $value || '' === $value || [] === $value) {
            return null;
        }
        $key = $definition->key;

        return match ($definition->kind) {
            FieldKind::Text, FieldKind::LongText, FieldKind::Url, FieldKind::Contact, FieldKind::User => \is_string($value) ? $value : throw new \InvalidArgumentException(\sprintf('The field "%s" takes a text.', $key)),
            FieldKind::Number => \is_int($value) || \is_float($value) || (\is_string($value) && is_numeric($value)) ? $value : throw new \InvalidArgumentException(\sprintf('The field "%s" takes a number.', $key)),
            FieldKind::Date, FieldKind::DateTime => $value instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($value) : throw new \InvalidArgumentException(\sprintf('The field "%s" takes a date.', $key)),
            FieldKind::Boolean => \is_bool($value) ? $value : throw new \InvalidArgumentException(\sprintf('The field "%s" takes true or false.', $key)),
            FieldKind::Select => \is_string($value) && isset($definition->options[$value]) ? $value : throw new \InvalidArgumentException(\sprintf('The field "%s" takes one of %s.', $key, implode(', ', array_keys($definition->options)))),
            FieldKind::MultiSelect => $this->selected($definition, $value),
        };
    }

    /**
     * @return list<string> the option keys of a multi-select, each once
     */
    private function selected(FieldDefinition $definition, mixed $value): array
    {
        $refused = new \InvalidArgumentException(\sprintf('The field "%s" takes a list of %s.', $definition->key, implode(', ', array_keys($definition->options))));
        if (!\is_array($value) || !array_is_list($value)) {
            throw $refused;
        }
        $selected = [];
        foreach ($value as $one) {
            if (!\is_string($one) || !isset($definition->options[$one])) {
                throw $refused;
            }
            $selected[$one] = $one;
        }

        return array_values($selected);
    }
}
