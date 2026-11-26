<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Mcp;

use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Infrastructure\Api\WorkJson;

/**
 * The labels of the organisation, as GET /api/v1/labels answers them.
 */
final readonly class ListLabelsTool implements ReadTool
{
    public function __construct(private WorkQueries $queries)
    {
    }

    public function name(): string
    {
        return 'foresi_list_labels';
    }

    public function title(): string
    {
        return 'List labels';
    }

    public function scope(): string
    {
        return 'tasks:read';
    }

    public function description(): string
    {
        return 'Returns the labels of the organisation by name, each {id, name, colour}; a task takes them by id as label_ids of foresi_create_task and foresi_update_task.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function call(array $arguments): ToolOutput
    {
        $labels = $this->queries->labels();

        return new ToolOutput(['items' => array_map(WorkJson::label(...), $labels)], \sprintf('%d labels.', \count($labels)));
    }
}
