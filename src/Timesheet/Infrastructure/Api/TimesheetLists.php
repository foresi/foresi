<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Api;

use Foresi\Shared\Domain\Id;
use Foresi\Timesheet\Application\TimeQueries;
use Foresi\Work\Infrastructure\Api\WorkLists;
use Foresi\Work\Infrastructure\Api\WorkPage;

/**
 * The time read by cursor, for the REST API and MCP alike, as Work reads its
 * lists: the newest first, a limit between 1 and 100 and one row more read to
 * know whether more follow. A cursor that is no id lists nothing.
 */
final readonly class TimesheetLists
{
    public function __construct(private TimeQueries $time)
    {
    }

    public function timeEntries(TimeEntryListQuery $query, ?string $afterId): WorkPage
    {
        if (null !== $afterId && !Id::isValid($afterId)) {
            return new WorkPage([], null);
        }
        $limit = max(1, min($query->limit, WorkLists::MAX_LIMIT));
        $rows = $this->time->slice($query->filter(), $afterId, $limit + 1);
        $next = null;
        if (\count($rows) > $limit) {
            $rows = \array_slice($rows, 0, $limit);
            $next = $rows[$limit - 1]->id;
        }

        return new WorkPage(array_map(TimesheetJson::timeEntry(...), $rows), $next);
    }
}
