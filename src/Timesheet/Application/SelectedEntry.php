<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Application;

/**
 * An entry chosen for a line: what it said then, and the minutes it bills.
 */
final readonly class SelectedEntry
{
    public function __construct(
        public string $id,
        public string $fingerprint,
        public int $minutes,
    ) {
    }
}
