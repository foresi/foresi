<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

/** A field of an import target that a source column can be mapped to. */
final readonly class FieldDefinition
{
    /**
     * @param list<string> $aliases lower-case, diacritics-free header names that suggest this field
     * @param list<string> $choices the allowed values of a Choice field
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public FieldType $type,
        public bool $required = false,
        public array $aliases = [],
        public array $choices = [],
        public string $group = '',
    ) {
    }
}
