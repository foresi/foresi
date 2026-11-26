<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Field\Mcp;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;
use Foresi\Shared\Infrastructure\Field\FieldList;

/**
 * The custom fields the modules add to the records, as GET /api/v1/fields lists
 * them (ADR 0045). It only reads: no tool creates or changes a field.
 */
final readonly class ListFieldsTool implements ReadTool
{
    public function __construct(private FieldList $fields)
    {
    }

    public function name(): string
    {
        return 'foresi_list_fields';
    }

    public function title(): string
    {
        return 'List custom fields';
    }

    public function scope(): string
    {
        return ScopeGuard::ANY;
    }

    public function description(): string
    {
        return 'Returns the custom fields modules add to contacts, deals, tasks, projects and products, of every record kind the token may read, or of one with record: {items}. '
            .'Each has its key, record, kind (text, long_text, number, date, datetime, boolean, url, select, multi_select, contact, user), label, options (key and label, for a select or a multi_select), module and whether it is required, filterable, sortable and searchable. '
            .'The get and search tools of a record return its values under custom_fields by key; its create and update tools take custom_fields with the keys to set, null clears one. '
            .'A select takes an option key, a multi_select a list of them, a date YYYY-MM-DD, a datetime ISO 8601 with an offset, a contact or a user its id. '
            .'A filterable field is a field of the filter of the record\'s search tool, by its key. Fields cannot be created or changed here.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'record' => ToolSchema::choiceSchema($this->fields->kinds(), 'Only the fields of this record kind.'),
            ],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $items = $this->fields->of(ToolSchema::text($arguments, 'record'));
        } catch (\InvalidArgumentException $invalid) {
            throw ToolRefusal::invalidArgument($invalid->getMessage());
        } catch (\DomainException) {
            throw new ToolRefusal(ToolRefusal::INSUFFICIENT_SCOPE, 'The token may not read this record kind.');
        }
        $count = \count($items);

        return new ToolOutput(['items' => $items], 0 === $count ? 'No custom fields.' : (1 === $count ? '1 custom field.' : \sprintf('%d custom fields.', $count)));
    }
}
