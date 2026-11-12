<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Application\Query;

/**
 * The events of the active organisation, as an agent reads them: those that have
 * a position, from the last thirty days, in the order of their positions. The
 * feed does not decide who may read which event; the caller passes only the
 * names it may show.
 */
interface EventFeed
{
    public const int MAX_LIMIT = 100;

    /**
     * @param int|null     $afterPosition only events with a higher position; null for the first slice
     * @param list<string> $names         only events of these names; none gives an empty slice
     * @param int          $limit         how many at most, clamped to 1..MAX_LIMIT
     */
    public function slice(?int $afterPosition, array $names, int $limit): EventSlice;
}
