<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Domain;

enum IdokladJobState: string
{
    /** Waiting for a worker. */
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function isBusy(): bool
    {
        return self::Pending === $this || self::Running === $this;
    }
}
