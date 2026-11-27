<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Mcp;

use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;
use Foresi\Work\Application\ManageRecurringTasks;
use Foresi\Work\Domain\RecurringTask;
use Foresi\Work\Domain\RecurringTaskState;
use Foresi\Work\Infrastructure\Api\RecurringTaskJson;

/**
 * The recurring tasks, as GET /api/v1/recurring-tasks lists them.
 */
final readonly class ListRecurringTasksTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private ManageRecurringTasks $recurring, private WorkToolPages $pages)
    {
    }

    public function name(): string
    {
        return 'foresi_list_recurring_tasks';
    }

    public function title(): string
    {
        return 'List recurring tasks';
    }

    public function scope(): string
    {
        return 'tasks:read';
    }

    public function description(): string
    {
        return 'Lists the recurring tasks of the projects the person sees, such as weekly retainer slots: each with its title as typed (with period variables such as {week}), project, state (active, paused, ended), next_on, the date of the next task, created_count and failing, true when the last run could not create its task. '
            .'The active ones come first, the soonest next date first. Read one with foresi_get_recurring_task. Only the people of the organisation see them.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'state' => ToolSchema::choiceSchema(['active', 'paused', 'ended'], 'Only the recurring tasks in this state.'),
                'project_id' => ToolSchema::idSchema('Only those of this project, as foresi_search_projects returns it.'),
            ],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $state = ToolSchema::text($arguments, 'state');
        $known = null === $state ? null : RecurringTaskState::tryFrom($state) ?? throw new ToolRefusal(ToolRefusal::INVALID_ARGUMENT, 'The state is active, paused or ended.');
        $projectId = ToolSchema::text($arguments, 'project_id');
        $all = $this->pages->read(fn (): array => $this->recurring->all($known));
        if (null !== $projectId) {
            $all = array_values(array_filter($all, static fn (RecurringTask $recurring): bool => $recurring->project()->id() === $projectId));
        }

        return new ToolOutput(['data' => array_map(RecurringTaskJson::item(...), $all)], \sprintf('%d recurring tasks.', \count($all)));
    }

    public function staffOnly(): bool
    {
        return true;
    }
}
