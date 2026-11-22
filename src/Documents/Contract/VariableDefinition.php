<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Contract;

/**
 * One variable of a source. The name is below the source's prefix ("name" for
 * {{ client.name }}); the example is what the template preview shows: a string,
 * or for a list such as client.persons a list of rows with example values.
 */
final readonly class VariableDefinition
{
    /**
     * @param string|list<array<string, string>> $example
     */
    public function __construct(
        public string $name,
        public string $label,
        public string|array $example,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('The variable name "%s" is not a lowercase identifier.', $name));
        }
    }
}
