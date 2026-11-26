<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Operation;

use Foresi\Shared\Contract\Operation\Field;

/**
 * The JSON Schema of an operation's input, from its fields and their #[Field]:
 * for the MCP tool all fields, for the REST body those not in the path. The
 * custom fields of a record are described by the caller, from their definitions.
 */
final class InputSchema
{
    /**
     * @param list<OperationField>                          $fields
     * @param (\Closure(string): array<string, mixed>)|null $customFields the schema of the custom fields of a record kind; a plain object without it
     *
     * @return array<string, mixed>
     */
    public static function of(array $fields, ?\Closure $customFields = null): array
    {
        $properties = [];
        $required = [];
        foreach ($fields as $field) {
            $properties[$field->wire] = null !== $field->customFields && $customFields instanceof \Closure
                ? $customFields($field->customFields) + (null === $field->describe ? [] : ['description' => $field->describe->description])
                : self::property($field);
            if ($field->required) {
                $required[] = $field->wire;
            }
        }

        return ['type' => 'object', 'properties' => [] === $properties ? new \stdClass() : $properties]
            + ([] === $required ? [] : ['required' => $required])
            + ['additionalProperties' => false];
    }

    /**
     * @return array<string, mixed>
     */
    public static function property(OperationField $field): array
    {
        $type = match ($field->type) {
            'int' => 'integer',
            'bool' => 'boolean',
            default => $field->type,
        };
        $describe = $field->describe;
        $property = ['type' => $field->nullable ? [$type, 'null'] : $type];
        if ('array' === $field->type && Field::ONE_OBJECT === $describe?->items) {
            $property['type'] = $field->nullable ? ['object', 'null'] : 'object';
        } elseif ('array' === $field->type) {
            $property['items'] = match ($describe?->items) {
                Field::OBJECTS => ['type' => 'object'],
                Field::OBJECTS_OR_STRINGS => ['anyOf' => [['type' => 'object'], ['type' => 'string']]],
                default => ['type' => $describe->items ?? 'string'] + (null === $describe?->enum ? [] : ['enum' => $describe->enum]),
            };
        } elseif (null !== $describe?->enum) {
            $property['enum'] = $field->nullable ? [...$describe->enum, null] : $describe->enum;
        }
        if (null !== $describe?->maxLength) {
            $property['maxLength'] = $describe->maxLength;
        }
        if (null !== $describe?->format) {
            $property['format'] = $describe->format;
        }
        if ($describe instanceof Field) {
            $property['description'] = $describe->description;
        }

        return $property;
    }
}
