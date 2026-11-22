<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Infrastructure\Mcp;

use Foresi\Calendar\Application\CalendarRefused;
use Foresi\Calendar\Application\FeedReads;
use Foresi\Calendar\Domain\CalendarFeed;
use Foresi\Calendar\Infrastructure\Api\CalendarJson;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\StaffOnlyTool;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

final readonly class ListCalendarFeedsTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private FeedReads $reads, private InstanceClock $clock)
    {
    }

    public function name(): string
    {
        return 'foresi_list_calendar_feeds';
    }

    public function title(): string
    {
        return 'List my calendar feeds';
    }

    public function scope(): string
    {
        return 'calendar:read';
    }

    public function description(): string
    {
        return 'Returns your own calendar feeds, oldest first: {feeds} with the id, name, created_at, last_fetched_at (when a calendar app last fetched it, recorded at most once an hour) and revoked_at of each. '
            .'The addresses are never shown again after foresi_create_calendar_feed. Use an id with foresi_rename_calendar_feed or foresi_revoke_calendar_feed.';
    }

    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $feeds = $this->reads->mine();
        } catch (CalendarRefused $refused) {
            throw ToolRefusal::of($refused);
        }
        $zone = $this->clock->timezone();
        $count = \count($feeds);

        return new ToolOutput(
            ['feeds' => array_map(static fn (CalendarFeed $feed): array => CalendarJson::feed($feed, $zone), $feeds)],
            1 === $count ? '1 calendar feed.' : \sprintf('%d calendar feeds, oldest first.', $count),
        );
    }
}
