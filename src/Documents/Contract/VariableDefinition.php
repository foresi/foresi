<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Contract;

/**
 * One variable of a source. The name is below the source's prefix ("name" for
 * {{ client.name }}), and since module API 1.9 it may be a path of such names
 * ("custom.acme_fleet_industry" for {{ client.custom.acme_fleet_industry }}),
 * whose value the source nests the same way; the example is what the template preview shows: a string,
 * or for a list such as client.persons a list of rows with example values.
 *
 * An image variable, such as firm.logo, prints as an image: its value is the
 * name the source gives a local file (empty when there is none), and the
 * source, a DocumentImageSource, tells where that file is.
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
        public bool $image = false,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('The variable name "%s" is not a lowercase identifier or a path of them.', $name));
        }
        if ($image && !\is_string($example)) {
            throw new \InvalidArgumentException(\sprintf('The image variable "%s" has a name of a file as its example, not a list.', $name));
        }
    }
}
