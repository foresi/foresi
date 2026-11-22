<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Mcp;

use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\ActivityReads;
use Foresi\Activities\Infrastructure\Api\ActivitiesJson;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\StaffOnlyTool;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

final readonly class GetActivityTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private ActivityReads $reads, private ActivitiesJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_get_activity';
    }

    public function title(): string
    {
        return 'Get an activity';
    }

    public function scope(): string
    {
        return 'activities:read';
    }

    public function description(): string
    {
        return 'Returns one activity: its type, subject, notes in Markdown, planned date or time, whether and when it was done with its outcome, its owner, its records (a record that is gone is available: false) and its files. Change it with foresi_update_activity.';
    }

    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['activity_id' => ToolArguments::idSchema('The id of the activity.')],
            'required' => ['activity_id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $activity = $this->reads->activity((string) ToolArguments::text($arguments, 'activity_id'));

            return new ToolOutput($this->json->activity($activity), \sprintf('The activity "%s" is %s.', $activity->subject(), $activity->isDone() ? 'done' : 'planned'));
        } catch (ActivitiesRefused $refused) {
            throw ToolRefusal::of($refused);
        }
    }
}
