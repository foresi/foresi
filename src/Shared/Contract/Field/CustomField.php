<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Contract\Field;

/**
 * Declares a property of a record extension (RecordExtension) as a custom field of
 * the record (ADR 0045). The property is a nullable mapped column of a type its kind
 * allows (FieldKind::columnTypes()); its key is the module's id and the property's
 * name in snake case, such as "acme_fleet_industry".
 *
 * Every flag is off unless it says otherwise: a field is shown in its record's
 * dialogs and detail, and is part of the API, the templates and the import, but is
 * filtered, sorted, required, searched or shown to guests only when it says so.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class CustomField
{
    /** The key of an option: lowercase letters, digits and underscores. */
    public const string OPTION = '/^[a-z0-9_]+$/';

    /**
     * @param string                $label      the translation key of its name
     * @param array<string, string> $options    of a select or a multi-select, and only of those: the translation key of each option's label, by its key
     * @param bool                  $filterable a condition of the record's saved views and quick filters
     * @param bool                  $sortable   a sort of the record's list, where the list sorts by columns; never a long text or a multi-select
     * @param bool                  $required   the record's dialogs ask for it
     * @param bool                  $guests     guests see it too
     * @param bool                  $api        part of the REST and MCP payloads of the record
     * @param bool                  $template   readable in document templates
     * @param bool                  $import     offered by the import and the export
     * @param bool                  $searchable read by the record's text search; a text, a long text or a URL only
     */
    public function __construct(
        public FieldKind $kind,
        public string $label,
        public array $options = [],
        public bool $filterable = false,
        public bool $sortable = false,
        public bool $required = false,
        public bool $guests = false,
        public bool $api = true,
        public bool $template = true,
        public bool $import = true,
        public bool $searchable = false,
    ) {
    }
}
