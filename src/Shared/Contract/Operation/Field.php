<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Contract\Operation;

/**
 * Describes one field of an operation's input for the clients: what it means,
 * its longest value and the values it takes. The REST body schema and the MCP
 * input schema are derived from it; the use case still checks every rule.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Field
{
    /** The items of a list are JSON objects, such as the conditions of a filter. */
    public const string OBJECTS = 'object';
    /** The items of a list are JSON objects or strings, such as the columns of a view. */
    public const string OBJECTS_OR_STRINGS = 'object|string';
    /** The value is one JSON object, such as a mapping with its kind and shares (an array parameter, not a list). */
    public const string ONE_OBJECT = 'one-object';
    /** The pattern of an ISO 4217 currency code; the use case checks it against the list (GET /api/v1/currencies). */
    public const string CURRENCY = '^[A-Z]{3}$';

    /**
     * @param list<string>|null $enum    the only values the field takes; for a list, of its items
     * @param string|null       $pattern a regular expression (ECMA 262, as JSON Schema has it) a string value matches, such as CURRENCY
     * @param string|null       $items   the type of the items of a list: "string" when null, OBJECTS or OBJECTS_OR_STRINGS; ONE_OBJECT for a value that is one object, not a list
     */
    public function __construct(
        public string $description,
        public ?int $maxLength = null,
        public ?array $enum = null,
        public ?string $items = null,
        public ?string $format = null,
        public ?string $pattern = null,
    ) {
    }
}
