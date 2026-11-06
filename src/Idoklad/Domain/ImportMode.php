<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Domain;

/**
 * A check does everything a run does inside a transaction it rolls back, and
 * writes no files, so its report says what a run would do. A run commits.
 */
enum ImportMode: string
{
    case Check = 'check';
    case Run = 'run';

    public function writes(): bool
    {
        return self::Run === $this;
    }
}
