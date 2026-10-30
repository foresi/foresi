<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

/** The steps of an import; Import::moveTo() allows only the transitions listed here. */
enum ImportState: string
{
    case Uploaded = 'uploaded';
    case Reading = 'reading';
    case Mapping = 'mapping';
    case Checking = 'checking';
    case Checked = 'checked';
    case Queued = 'queued';
    case Running = 'running';
    case Finished = 'finished';
    case FinishedWithErrors = 'finished_with_errors';
    /** The error threshold was reached and the run stopped. */
    case Stopped = 'stopped';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Undoing = 'undoing';
    case Undone = 'undone';

    public function canMoveTo(self $next): bool
    {
        return \in_array($next, $this->next(), true);
    }

    /** Nothing more happens to the import's data from here, except an undo of a finished one. */
    public function isEnd(): bool
    {
        return match ($this) {
            self::Finished, self::FinishedWithErrors, self::Stopped, self::Failed, self::Cancelled, self::Undone => true,
            default => false,
        };
    }

    /**
     * @return list<self>
     */
    private function next(): array
    {
        return match ($this) {
            self::Uploaded => [self::Reading, self::Cancelled],
            self::Reading => [self::Mapping, self::Failed, self::Cancelled],
            self::Mapping => [self::Checking, self::Cancelled],
            self::Checking => [self::Checked, self::Failed, self::Cancelled],
            self::Checked => [self::Mapping, self::Queued, self::Cancelled],
            self::Queued => [self::Running, self::Cancelled],
            self::Running => [self::Finished, self::FinishedWithErrors, self::Failed, self::Stopped, self::Cancelled],
            self::Finished, self::FinishedWithErrors => [self::Undoing],
            self::Undoing => [self::Undone, self::Failed],
            self::Stopped, self::Failed, self::Cancelled, self::Undone => [],
        };
    }
}
