<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Api;

/**
 * Describes one field of an operation's input for the clients: what it means,
 * its longest value and the values it takes. The REST body schema and the MCP
 * input schema are derived from it; the use case still checks every rule.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Field
{
    /**
     * @param list<string>|null $enum  the only values the field takes; for a list, of its items
     * @param string|null       $items the type of the items of a list, such as "string"
     */
    public function __construct(
        public string $description,
        public ?int $maxLength = null,
        public ?array $enum = null,
        public ?string $items = null,
        public ?string $format = null,
    ) {
    }
}
