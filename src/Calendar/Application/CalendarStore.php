<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Application;

use Foresi\Calendar\Domain\CalendarFeed;

/**
 * Where the calendar feeds are kept.
 */
interface CalendarStore
{
    public function feed(string $id): ?CalendarFeed;

    public function feedByTokenHash(string $tokenHash): ?CalendarFeed;

    /**
     * @return list<CalendarFeed> the person's feeds, oldest first, revoked ones too
     */
    public function feedsOf(string $userId): array;

    public function add(CalendarFeed $feed): void;

    /**
     * Stores what changed on the feeds read, such as the time of a fetch.
     */
    public function save(): void;

    /**
     * Runs the work and stores what it changed, with its audit entries, in one transaction.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed;
}
