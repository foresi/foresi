<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Operation;

/**
 * The JSON Schema of an operation's input, from its fields and their #[Field]:
 * for the MCP tool all fields, for the REST body those not in the path.
 */
final class InputSchema
{
    /**
     * @param list<OperationField> $fields
     *
     * @return array<string, mixed>
     */
    public static function of(array $fields): array
    {
        $properties = [];
        $required = [];
        foreach ($fields as $field) {
            $properties[$field->wire] = self::property($field);
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
        if ('array' === $field->type) {
            $property['items'] = ['type' => $describe->items ?? 'string'] + (null === $describe?->enum ? [] : ['enum' => $describe->enum]);
        } elseif (null !== $describe?->enum) {
            $property['enum'] = $field->nullable ? [...$describe->enum, null] : $describe->enum;
        }
        if (null !== $describe?->maxLength) {
            $property['maxLength'] = $describe->maxLength;
        }
        if (null !== $describe?->format) {
            $property['format'] = $describe->format;
        }
        if ($describe instanceof \App\Shared\Application\Api\Field) {
            $property['description'] = $describe->description;
        }

        return $property;
    }
}
