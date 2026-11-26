<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Mcp;

use Foresi\Meetings\Application\MeetingPeople;
use Foresi\Meetings\Application\MeetingsRefused;
use Foresi\Meetings\Application\SeriesReads;
use Foresi\Meetings\Infrastructure\Api\MeetingsJson;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;

/**
 * The series the person sees, as GET /api/v1/meetings/series writes them, and
 * the people of the firm a series or a meeting can hold.
 */
final readonly class ListSeriesTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private SeriesReads $series, private MeetingPeople $people)
    {
    }

    public function name(): string
    {
        return 'foresi_list_meeting_series';
    }

    public function title(): string
    {
        return 'List meeting series';
    }

    public function scope(): string
    {
        return 'meetings:read';
    }

    public function description(): string
    {
        return 'Returns the meeting series you see, by name, {items}, each with its visibility (members: its members, the attendees and the owners see its meetings; organisation: everyone in the firm), agenda_template, default_project_id, members (id, name, default_attendee), the number of its meetings and the day of the last one; the archived series only when archived is true. '
            .'Also returns {people}: the owners and members of the firm, {id, name}, whose ids are the member_ids of foresi_create_meeting_series and the attendee_ids of foresi_create_meeting. Use a series id with foresi_create_meeting and foresi_search_meetings.';
    }

    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['archived' => ['type' => 'boolean', 'description' => 'true includes the archived series.']],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $series = $this->series->views(ToolSchema::flag($arguments, 'archived'));
        } catch (MeetingsRefused $refused) {
            throw ToolRefusal::of($refused);
        }
        $people = array_values(array_map(static fn ($person): array => ['id' => $person->membershipId, 'name' => $person->name], $this->people->staff()));
        $count = \count($series);

        return new ToolOutput(
            ['items' => array_map(MeetingsJson::series(...), $series), 'people' => $people],
            (1 === $count ? '1 series' : \sprintf('%d series', $count)).\sprintf(', and %d people of the firm.', \count($people)),
        );
    }
}
