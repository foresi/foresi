<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

/**
 * What the user decided about a source: the field of each column (null = do not
 * import it) and what the source values of a field mean.
 */
final readonly class Mapping
{
    /**
     * @param array<int, string|null>              $columns   column index => field key
     * @param array<string, array<string, string>> $valueMaps field key => source text => target value
     */
    public function __construct(
        public array $columns = [],
        public array $valueMaps = [],
    ) {
    }

    /**
     * Identifies a set of headers, so a mapping can be remembered for it.
     *
     * @param list<string> $headers
     */
    public static function signature(array $headers): string
    {
        return sha1(implode("\n", array_map(HeaderName::normalise(...), $headers)));
    }

    public function fieldFor(int $column): ?string
    {
        return $this->columns[$column] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function valueMap(string $fieldKey): array
    {
        return $this->valueMaps[$fieldKey] ?? [];
    }

    /**
     * @param list<FieldDefinition> $fields
     *
     * @return list<string> keys of the required fields no column is mapped to
     */
    public function missingRequired(array $fields): array
    {
        $mapped = array_filter($this->columns, static fn (?string $key): bool => null !== $key);
        $missing = [];
        foreach ($fields as $field) {
            if ($field->required && !\in_array($field->key, $mapped, true)) {
                $missing[] = $field->key;
            }
        }

        return $missing;
    }

    /**
     * @return array{columns: array<int, string|null>, valueMaps: array<string, array<string, string>>}
     */
    public function toArray(): array
    {
        return ['columns' => $this->columns, 'valueMaps' => $this->valueMaps];
    }

    /**
     * @param array<array-key, mixed> $data as stored by toArray(), possibly through JSON
     */
    public static function fromArray(array $data): self
    {
        $columns = [];
        foreach (self::section($data, 'columns') as $column => $key) {
            if (!is_numeric($column) || null !== $key && !\is_string($key)) {
                throw new \InvalidArgumentException('A mapping column must be an index with a field key or null.');
            }
            $columns[(int) $column] = $key;
        }
        $valueMaps = [];
        foreach (self::section($data, 'valueMaps') as $fieldKey => $map) {
            if (!\is_array($map)) {
                throw new \InvalidArgumentException('A mapping value map must be a list of source values.');
            }
            foreach ($map as $source => $target) {
                if (!\is_string($target)) {
                    throw new \InvalidArgumentException('A mapping value must be text.');
                }
                $valueMaps[(string) $fieldKey][(string) $source] = $target;
            }
        }

        return new self($columns, $valueMaps);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function section(array $data, string $name): array
    {
        $section = $data[$name] ?? [];

        return \is_array($section) ? $section : throw new \InvalidArgumentException(\sprintf('The mapping section "%s" must be an array.', $name));
    }
}
