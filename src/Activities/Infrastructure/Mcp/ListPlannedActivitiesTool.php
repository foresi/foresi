<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Mcp;

use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\ActivityReads;
use Foresi\Activities\Infrastructure\Api\ActivitiesJson;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;

final readonly class ListPlannedActivitiesTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private ActivityReads $reads, private ActivitiesJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_list_planned_activities';
    }

    public function title(): string
    {
        return 'List my planned activities';
    }

    public function scope(): string
    {
        return 'activities:read';
    }

    public function description(): string
    {
        return 'Returns the caller\'s own activities that are planned and not done, from a day up to the day before another (today and 30 days ahead when left out, at most 400 days), the soonest first. Mark one done with foresi_complete_activity.';
    }

    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['from' => ['type' => 'string', 'format' => 'date', 'description' => 'The first day, YYYY-MM-DD; today when left out.'], 'to' => ['type' => 'string', 'format' => 'date', 'description' => 'The day after the last, YYYY-MM-DD.']],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $activities = $this->reads->myPlanned(ToolSchema::text($arguments, 'from'), ToolSchema::text($arguments, 'to'));

            return new ToolOutput(['items' => array_map($this->json->activity(...), $activities)], \sprintf('%d planned activities.', \count($activities)));
        } catch (ActivitiesRefused $refused) {
            throw ToolRefusal::of($refused);
        }
    }
}
