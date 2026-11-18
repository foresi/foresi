<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\View\Mcp;

use Foresi\Shared\Application\View\ListDefinitions;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\View\Api\ViewJson;

/**
 * The lists saved views filter, as GET /api/v1/lists writes them.
 */
final readonly class ListListsTool implements ReadTool
{
    public function __construct(private ListDefinitions $lists, private ViewJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_list_lists';
    }

    public function title(): string
    {
        return 'List the lists views filter';
    }

    public function scope(): string
    {
        return 'views:read';
    }

    public function description(): string
    {
        return 'Returns every list saved views apply to, such as invoicing.invoices, so a filter can be built without guessing: {lists, date_values}. '
            .'Each list has its fields (key, type, label, the operators it takes and, for an enum, its options), its columns (key, label, sortable, shown by default, locked) and the system keys of the views its module ships. '
            .'A condition is {field, op, value}; a date value is a day YYYY-MM-DD, {month: YYYY-MM} or {relative: rule}, with {relative, n} for the counted rules, and op between takes {from, to}. '
            .'Use the list key with foresi_list_views and foresi_create_view, and conditions as filter in the search tools, such as foresi_search_invoices.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function call(array $arguments): ToolOutput
    {
        $lists = array_map($this->json->list(...), array_values($this->lists->all()));

        return new ToolOutput(['lists' => $lists, 'date_values' => ViewJson::dateValues()], \sprintf('%d lists.', \count($lists)));
    }
}
