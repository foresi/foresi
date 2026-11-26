<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Contract\Field;

/**
 * One custom field of a record kind, as a field source declares it (ADR 0045): its
 * key, which never changes and holds no dot, the record it belongs to, its kind, the
 * translation key of its label, its options and its flags (CustomField), and the id
 * of the module that adds it.
 */
final readonly class FieldDefinition
{
    /** A key: the module's id and the property's name in snake case. */
    public const string KEY = '/^[a-z][a-z0-9_]*$/';

    /**
     * @param string                $record  the record kind, such as ContactExtension::RECORD
     * @param array<string, string> $options the translation key of each option's label, by its key
     */
    public function __construct(
        public string $key,
        public string $record,
        public FieldKind $kind,
        public string $label,
        public array $options,
        public string $module,
        public bool $filterable = false,
        public bool $sortable = false,
        public bool $required = false,
        public bool $guests = false,
        public bool $api = true,
        public bool $template = true,
        public bool $import = true,
        public bool $searchable = false,
    ) {
        if (1 !== preg_match(self::KEY, $key)) {
            throw new \InvalidArgumentException(\sprintf('A field key is snake case without a dot, such as "acme_fleet_industry", not "%s".', $key));
        }
    }
}
