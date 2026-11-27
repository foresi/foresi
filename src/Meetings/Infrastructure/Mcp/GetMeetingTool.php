<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Mcp;

use Foresi\Meetings\Application\ActionItemReads;
use Foresi\Meetings\Application\MeetingReads;
use Foresi\Meetings\Application\MeetingsRefused;
use Foresi\Meetings\Infrastructure\Api\MeetingsJson;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;

/**
 * One meeting with its minutes, as GET /api/v1/meetings/{meetingId} writes it.
 */
final readonly class GetMeetingTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private MeetingReads $meetings, private ActionItemReads $tasks)
    {
    }

    public function name(): string
    {
        return 'foresi_get_meeting';
    }

    public function title(): string
    {
        return 'Get a meeting';
    }

    public function scope(): string
    {
        return 'meetings:read';
    }

    public function description(): string
    {
        return 'Returns one meeting you see: its title, series, the day held_on and the time starts_at, its attendees, the records it links that you see, the minutes in Markdown and their version. '
            .'The minutes keep the tokens "[text](task:id)" of the tasks made from them. action_items are those tasks, live from Work, the ones whose token was taken out of the minutes too; carry_over the open tasks of earlier meetings of the series, by meeting, newest first: every open task of the previous meeting, and of older meetings those overdue or due within 7 days of this meeting\'s day. Only the tasks you see are listed. '
            .'Send the version with foresi_set_meeting_minutes, which refuses a save when someone changed the minutes since. Find ids with foresi_search_meetings.';
    }

    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ToolSchema::idSchema('The id of the meeting, as foresi_search_meetings returns it.')],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $view = $this->meetings->view(ToolSchema::text($arguments, 'id') ?? '');
        } catch (MeetingsRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput(MeetingsJson::meeting($view, $this->tasks->page($view->meeting, $view->meeting->minutes())), \sprintf('The meeting %s, minutes at version %d.', MeetingsJson::summary($view->meeting), $view->meeting->version()));
    }
}
