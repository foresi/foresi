<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Operation;

use Foresi\Shared\Application\Api\Field;

/**
 * Turns what a client sent, by the names it sends them under, into the input
 * object of an operation. It checks the shape only: every field is known,
 * required ones are there, each has its type and allowed values. A field left
 * out takes its default, Omitted for a partial update. The rules of the data
 * are the use case's.
 */
final class InputMapper
{
    /**
     * @param array<mixed> $sent the fields by wire name, path fields included
     *
     * @throws InvalidInput
     */
    public static function map(OperationDefinition $definition, array $sent): object
    {
        $known = array_map(static fn (OperationField $field): string => $field->wire, $definition->fields);
        foreach (array_keys($sent) as $name) {
            if (!\in_array((string) $name, $known, true)) {
                throw new InvalidInput((string) $name, 'is not a field of this operation.');
            }
        }
        $arguments = [];
        foreach ($definition->fields as $field) {
            if (!\array_key_exists($field->wire, $sent)) {
                if ($field->required) {
                    throw new InvalidInput($field->wire, 'is required.');
                }
                continue;
            }
            $arguments[$field->name] = self::value($field, $sent[$field->wire]);
        }

        return new ($definition->input)(...$arguments);
    }

    private static function value(OperationField $field, mixed $value): mixed
    {
        if (null === $value) {
            return $field->nullable ? null : throw new InvalidInput($field->wire, 'must not be null.');
        }
        $valid = match ($field->type) {
            'string' => \is_string($value),
            'int' => \is_int($value),
            'bool' => \is_bool($value),
            default => Field::ONE_OBJECT === $field->describe?->items ? self::isItem($value, Field::OBJECTS) : \is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $item): bool => self::isItem($item, $field->describe?->items)),
        };
        if (!$valid) {
            throw new InvalidInput($field->wire, match ($field->type) {
                'string' => 'must be a string.', 'int' => 'must be a whole number.', 'bool' => 'must be true or false.', default => match ($field->describe?->items) {
                    Field::ONE_OBJECT => 'must be an object.', Field::OBJECTS => 'must be a list of objects.', Field::OBJECTS_OR_STRINGS => 'must be a list of objects or strings.', default => 'must be a list of strings.',
                },
            });
        }
        $longest = $field->describe?->maxLength;
        if (null !== $longest && \is_string($value) && mb_strlen($value) > $longest) {
            throw new InvalidInput($field->wire, \sprintf('must be at most %d characters.', $longest));
        }
        $allowed = $field->describe?->enum;
        if (null !== $allowed) {
            foreach (\is_array($value) ? $value : [$value] as $item) {
                if (!\in_array($item, $allowed, true)) {
                    throw new InvalidInput($field->wire, \sprintf('must be one of %s.', implode(', ', $allowed)));
                }
            }
        }

        return $value;
    }

    /**
     * An item of a list: a string, or for a list of objects a JSON object, which
     * arrives decoded as an array (an empty one included).
     */
    private static function isItem(mixed $item, ?string $items): bool
    {
        $object = \is_array($item) && ([] === $item || !array_is_list($item));

        return match ($items) {
            Field::OBJECTS => $object,
            Field::OBJECTS_OR_STRINGS => $object || \is_string($item),
            default => \is_string($item),
        };
    }
}
