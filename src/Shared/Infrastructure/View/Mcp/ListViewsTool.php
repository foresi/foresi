<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\View\Mcp;

use Foresi\Shared\Application\View\SavedViewRefused;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;
use Foresi\Shared\Infrastructure\View\Api\ViewJson;

/**
 * The views of a list the token's person sees, as GET /api/v1/views writes them.
 */
final readonly class ListViewsTool implements ReadTool
{
    public function __construct(private SavedViews $views, private ViewJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_list_views';
    }

    public function title(): string
    {
        return 'List saved views';
    }

    public function scope(): string
    {
        return 'views:read';
    }

    public function description(): string
    {
        return 'Returns the saved views of a list you see, in their order: the shared ones, those the module ships among them, and your private ones, hidden ones too. '
            .'Each has its id, name, visibility, filters, sort, display, whether its tab shows a count and whether you may change it (may_edit). '
            .'List the records of a view with its id as view_id in the search tool of its list, such as foresi_search_invoices. Find the list keys with foresi_list_lists.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['list' => ['type' => 'string', 'description' => 'The list, such as "invoicing.invoices".']],
            'required' => ['list'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $views = $this->views->visible(ToolArguments::text($arguments, 'list') ?? '');
        } catch (SavedViewRefused $refused) {
            throw ToolRefusal::of($refused);
        }
        $count = \count($views);

        return new ToolOutput(['views' => array_map($this->json->view(...), $views)], 1 === $count ? '1 view.' : \sprintf('%d views, in their order.', $count));
    }
}
