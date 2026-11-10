<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Application;

use App\Work\Application\TimeEntryRow;
use App\Work\Domain\TimeEntry;

/**
 * What an entry said when it was chosen for an invoice: an edit since then
 * changes it, so the invoice is refused rather than billing other time.
 */
final class EntryFingerprint
{
    public static function ofRow(TimeEntryRow $row): string
    {
        return self::of($row->taskId, $row->workedOn, $row->minutes, $row->note, $row->billable);
    }

    public static function ofEntry(TimeEntry $entry): string
    {
        return self::of($entry->task()?->id(), $entry->workedOn(), $entry->minutes(), $entry->note(), $entry->isBillable());
    }

    private static function of(?string $taskId, \DateTimeImmutable $workedOn, ?int $minutes, string $note, bool $billable): string
    {
        return hash('xxh128', json_encode([$taskId, $workedOn->format('Y-m-d'), $minutes, $note, $billable], \JSON_THROW_ON_ERROR));
    }
}
