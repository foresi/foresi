<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Field;

use Foresi\Shared\Contract\Field\Fields;

/**
 * The one way a record's use case writes its custom fields (ADR 0045), inside its own
 * transaction: a guest writes only the fields open to guests, a new record gets a
 * value for every required field the actor sees, and the keys whose value changed
 * come back for the record's update event. Nothing is flushed here.
 */
final readonly class CustomFieldWrites
{
    public function __construct(private Fields $fields, private VisibleFields $visible)
    {
    }

    /**
     * @param array<string, mixed> $values  by field key, as Fields::write() takes them; null clears one
     * @param bool                 $created whether the record is being created, so the required fields need a value
     *
     * @return list<string> the keys whose value changed, in the order given
     *
     * @throws FieldRefused for an unknown or hidden key, a required field left empty on create, or a value its kind does not take
     */
    public function write(string $record, string $id, array $values, bool $created): array
    {
        foreach (array_keys($values) as $key) {
            if (!$this->fields->definition($record, $key) instanceof \Foresi\Shared\Contract\Field\FieldDefinition) {
                throw new FieldRefused($key, FieldRefused::UNKNOWN);
            }
            if (!$this->visible->definition($record, $key) instanceof \Foresi\Shared\Contract\Field\FieldDefinition) {
                throw new FieldRefused($key, FieldRefused::HIDDEN);
            }
        }
        if ($created) {
            foreach ($this->visible->definitions($record) as $definition) {
                $value = $values[$definition->key] ?? null;
                if ($definition->required && (null === $value || '' === $value || [] === $value)) {
                    throw new FieldRefused($definition->key, FieldRefused::REQUIRED);
                }
            }
        }
        if ([] === $values) {
            return [];
        }

        $before = [];
        foreach (array_keys($values) as $key) {
            $before[$key] = $this->fields->value($record, $id, $key);
        }
        try {
            $this->fields->write($record, $id, $values);
        } catch (\InvalidArgumentException $refused) {
            throw new FieldRefused($this->keyOf($refused, array_keys($values)), FieldRefused::INVALID, $refused->getMessage(), $refused);
        }

        $changed = [];
        foreach (array_keys($values) as $key) {
            if (!$this->same($before[$key], $this->fields->value($record, $id, $key))) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * Whether a value is the same as before: a date by its moment, a number by its
     * amount ("12.50" is "12.5"), anything else exactly, so false is no null.
     */
    private function same(mixed $before, mixed $after): bool
    {
        if ($before instanceof \DateTimeInterface && $after instanceof \DateTimeInterface) {
            return $before->getTimestamp() === $after->getTimestamp() && $before->format('u') === $after->format('u');
        }
        if (is_numeric($before) && is_numeric($after)) {
            return (float) $before === (float) $after;
        }

        return $before === $after;
    }

    /**
     * The key a refusal of Fields::write() names in quotes; the first key when it names none.
     *
     * @param non-empty-list<string> $keys
     */
    private function keyOf(\InvalidArgumentException $refused, array $keys): string
    {
        foreach ($keys as $key) {
            if (str_contains($refused->getMessage(), '"'.$key.'"')) {
                return $key;
            }
        }

        return $keys[0];
    }
}
