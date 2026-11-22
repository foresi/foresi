<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Application;

use Foresi\Calendar\Contract\CalendarEntry;
use Foresi\Calendar\Contract\CalendarEntrySource;
use Foresi\Calendar\Domain\CalendarFeed;
use Foresi\Calendar\Domain\FeedToken;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Instance\Application\InstanceClock;
use Psr\Log\LoggerInterface;

/**
 * What a calendar app gets from a feed address: the entries of the feed's owner
 * from every source, from 30 days ago to 365 days ahead (P6 of plan 32), as one
 * calendar. A source that throws is logged and left out, the others still answer,
 * the way the cash-flow forecast treats a failing source. An address opens only
 * while its feed is not revoked and its owner still works for the organisation.
 */
final readonly class CalendarFeedReader
{
    public const int DAYS_BACK = 30;
    public const int DAYS_AHEAD = 365;

    /**
     * @param iterable<CalendarEntrySource> $sources
     */
    public function __construct(
        private iterable $sources,
        private CalendarStore $store,
        private StaffCheck $staff,
        private InstanceClock $clock,
        private IcsWriter $writer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The feed of the token, or null for an unknown or revoked one (a malformed one is unknown), or one whose owner left the staff.
     */
    public function open(#[\SensitiveParameter] string $token): ?CalendarFeed
    {
        $feed = $this->store->feedByTokenHash(FeedToken::hash($token));
        if (!$feed instanceof CalendarFeed || $feed->isRevoked() || !$this->staff->isStaffMember($feed->userId())) {
            return null;
        }

        return $feed;
    }

    /**
     * The calendar of the feed, and the fetch recorded at most once an hour.
     */
    public function calendar(CalendarFeed $feed): string
    {
        $today = $this->clock->today();
        $from = $today->modify(\sprintf('-%d days', self::DAYS_BACK));
        $to = $today->modify(\sprintf('+%d days', self::DAYS_AHEAD + 1));
        $entries = [];
        foreach ($this->sources as $source) {
            try {
                foreach ($source->entriesFor($feed->userId(), $from, $to) as $entry) {
                    $entries[] = $entry;
                }
            } catch (\Throwable $failure) {
                $this->logger->error('Calendar source {source} failed; the feed is served without it.', ['source' => $source->key(), 'exception' => $failure]);
            }
        }
        usort($entries, static fn (CalendarEntry $a, CalendarEntry $b): int => [$a->start, $a->uid] <=> [$b->start, $b->uid]);
        $now = $this->clock->clock()->now()->setTimezone(new \DateTimeZone('UTC'));
        if ($feed->fetched($now)) {
            $this->store->save();
        }

        return $this->writer->write($feed->name(), $entries);
    }
}
