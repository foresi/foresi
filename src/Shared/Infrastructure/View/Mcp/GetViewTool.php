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
 * One saved view, as GET /api/v1/views/{id} writes it.
 */
final readonly class GetViewTool implements ReadTool
{
    public function __construct(private SavedViews $views, private ViewJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_get_view';
    }

    public function title(): string
    {
        return 'Get a saved view';
    }

    public function scope(): string
    {
        return 'views:read';
    }

    public function description(): string
    {
        return 'Returns one saved view you see, by its id or the system key of a view a module ships, such as "invoicing.invoices.overdue": its list, name, visibility, filters, sort, display and whether you may change it.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['view_id' => ['type' => 'string', 'description' => 'The id of the view as foresi_list_views gives it, or the system key of a view a module ships.']],
            'required' => ['view_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $view = $this->views->get(ToolArguments::text($arguments, 'view_id') ?? '');
        } catch (SavedViewRefused $refused) {
            throw ToolRefusal::of($refused);
        }
        $data = $this->json->view($view);

        return new ToolOutput($data, \sprintf('The view %s of %s.', \is_string($data['name']) ? $data['name'] : '', $view->listKey()));
    }
}
