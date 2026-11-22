<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Infrastructure\Api;

use Foresi\Calendar\Domain\CalendarFeed;

/**
 * A calendar feed as the API shows it: never its address, which only the answer that created it holds.
 */
final class CalendarJson
{
    private function __construct()
    {
    }

    /**
     * @return array{id: string, name: string, created_at: string, last_fetched_at: ?string, revoked_at: ?string}
     */
    public static function feed(CalendarFeed $feed, \DateTimeZone $zone): array
    {
        return [
            'id' => $feed->id(),
            'name' => $feed->name(),
            'created_at' => self::time($feed->createdAt(), $zone),
            'last_fetched_at' => self::time($feed->lastFetchedAt(), $zone),
            'revoked_at' => self::time($feed->revokedAt(), $zone),
        ];
    }

    /**
     * @return ($time is null ? null : string)
     */
    private static function time(?\DateTimeImmutable $time, \DateTimeZone $zone): ?string
    {
        return $time?->setTimezone($zone)->format(\DATE_ATOM);
    }
}
