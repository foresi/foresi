<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Mcp;

use Foresi\Meetings\Application\MeetingReads;
use Foresi\Meetings\Application\MeetingSearch;
use Foresi\Meetings\Application\MeetingsRefused;
use Foresi\Meetings\Application\View\MeetingLists;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Infrastructure\Api\MeetingsApiController;
use Foresi\Meetings\Infrastructure\Api\MeetingsJson;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Application\View\AppliedView;
use Foresi\Shared\Application\View\SavedViewRefused;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;
use Foresi\Shared\Infrastructure\Mcp\ToolArguments;

/**
 * The meetings the person sees, found by series, attendee, days, linked record,
 * text and the conditions of the list meetings.meetings (its custom fields among
 * them), as GET /api/v1/meetings writes them, a page at a time.
 */
final readonly class SearchMeetingsTool implements ReadTool, StaffOnlyTool
{
    public function __construct(
        private MeetingReads $meetings,
        private MeetingLists $lists,
        private SavedViews $views,
        private ScopeGuard $scope,
    ) {
    }

    public function name(): string
    {
        return 'foresi_search_meetings';
    }

    public function title(): string
    {
        return 'Search meetings';
    }

    public function scope(): string
    {
        return 'meetings:read';
    }

    public function description(): string
    {
        return 'Returns the meetings you see, newest first, without their minutes: {items, next_cursor}, each with its id, title, series, held_on, starts_at, attendees, linked records and the version of its minutes. '
            .'query finds a text in the title or the minutes; series_id, attendee_id (from foresi_list_meeting_series), from and to (days, YYYY-MM-DD) and link_kind with link_id (a project or a contact) narrow them. '
            .'filter adds conditions of the list meetings.meetings in the format of foresi_list_lists, such as {"conditions": [{"field": "when", "op": "is", "value": "upcoming"}]}, the custom fields of meetings among its fields; view_id lists what a saved view holds (it needs the views:read scope). trashed true lists the meetings in the trash instead. Read one with foresi_get_meeting.';
    }

    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'maxLength' => MeetingSearch::MAX_TEXT, 'description' => 'A text of the title or the minutes.'],
                'series_id' => ToolSchema::idSchema('Only the meetings of this series.'),
                'attendee_id' => ToolSchema::idSchema('Only the meetings this person of the firm attends.'),
                'from' => ToolSchema::dateSchema('Only the meetings held on this day or after.'),
                'to' => ToolSchema::dateSchema('Only the meetings held on this day or before.'),
                'link_kind' => ToolSchema::choiceSchema(['project', 'contact'], 'With link_id, only the meetings about this record.'),
                'link_id' => ToolSchema::idSchema('The id of the record of link_kind.'),
                'trashed' => ['type' => 'boolean', 'description' => 'true lists the meetings in the trash instead.'],
                'view_id' => ToolSchema::idSchema('A saved view of the list meetings.meetings, from foresi_list_views.'),
                'filter' => ['type' => 'object', 'description' => 'Conditions all meetings meet, {"conditions": [{"field", "op", "value"}]}, checked against the list meetings.meetings; its fields and operators are in foresi_list_lists.'],
            ] + ToolSchema::pagingSchema(),
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $limit = ToolSchema::limit($arguments);
        if ($limit < 1 || $limit > ToolSchema::MAX_LIMIT) {
            throw ToolRefusal::invalidArgument(\sprintf('The argument "limit" is from 1 to %d.', ToolSchema::MAX_LIMIT));
        }
        $afterId = ToolArguments::afterId(ToolSchema::text($arguments, 'cursor'));
        $viewId = ToolSchema::text($arguments, 'view_id');
        if (null !== $viewId && !$this->scope->allows(AppliedView::VIEWS_SCOPE)) {
            throw new ToolRefusal(ToolRefusal::INSUFFICIENT_SCOPE, \sprintf('The token does not have the "%s" scope a saved view needs.', AppliedView::VIEWS_SCOPE));
        }
        try {
            $applied = AppliedView::of($this->views, $this->scope, $this->lists->list(), $viewId, $arguments['filter'] ?? null);
            $search = MeetingsApiController::search(
                ToolSchema::text($arguments, 'query') ?? '',
                ToolSchema::text($arguments, 'series_id') ?? '',
                ToolSchema::text($arguments, 'attendee_id') ?? '',
                ToolSchema::text($arguments, 'from') ?? '',
                ToolSchema::text($arguments, 'to') ?? '',
                ToolSchema::text($arguments, 'link_kind') ?? '',
                ToolSchema::text($arguments, 'link_id') ?? '',
                ToolSchema::flag($arguments, 'trashed'),
                $limit + 1,
                $afterId,
            );
            $found = $this->meetings->search(new MeetingSearch($applied->filter ?? ViewFilter::all(), $search->text, $search->seriesId, $search->attendeeId, $search->from, $search->to, $search->linkKind, $search->linkId, trash: $search->trash, limit: $search->limit, afterId: $search->afterId));
        } catch (SavedViewRefused|MeetingsRefused $refused) {
            throw ToolRefusal::of($refused);
        }
        $more = \count($found) > $limit;
        $page = \array_slice($found, 0, $limit);

        return ToolArguments::page(
            array_map(fn (Meeting $meeting): array => MeetingsJson::row($this->meetings->viewOf($meeting)), $page),
            $more ? $page[array_key_last($page)]->id() : null,
            'meeting',
            'meetings',
        );
    }
}
