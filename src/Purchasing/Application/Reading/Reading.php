<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Reading;

/**
 * What the readers found in one file: at most one value per field, each with
 * its source.
 */
final readonly class Reading
{
    /**
     * @param array<value-of<ReadField>, ReadValue> $values
     */
    public function __construct(public array $values)
    {
    }

    public function get(ReadField $field): ?ReadValue
    {
        return $this->values[$field->value] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->values;
    }

    /**
     * @return array<string, array{value: string|int|array<mixed>, source: string}>
     */
    public function toArray(): array
    {
        $array = [];
        foreach ($this->values as $key => $value) {
            $array[$key] = ['value' => $value->value, 'source' => $value->source];
        }

        return $array;
    }

    /**
     * Entries of a field that no longer exists or that are malformed are
     * skipped, so an old stored reading never breaks a newer release.
     *
     * @param array<array-key, mixed> $array
     */
    public static function fromArray(array $array): self
    {
        $values = [];
        foreach ($array as $key => $entry) {
            $field = \is_string($key) ? ReadField::tryFrom($key) : null;
            if (null === $field || !\is_array($entry)) {
                continue;
            }
            $value = $entry['value'] ?? null;
            $source = $entry['source'] ?? null;
            if (!(\is_string($value) || \is_int($value) || \is_array($value)) || !\is_string($source)) {
                continue;
            }
            $values[$field->value] = new ReadValue($field, $value, $source);
        }

        return new self($values);
    }
}
