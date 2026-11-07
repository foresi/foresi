<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Domain;

/**
 * What a status of a project means, whatever the team calls it: reports,
 * filters and the completion date read the type, never the editable name.
 */
enum StatusType: string
{
    case Open = 'open';
    case Active = 'active';
    case Done = 'done';
    case Closed = 'closed';

    /**
     * A task in a finished status counts as completed.
     */
    public function isFinished(): bool
    {
        return self::Done === $this || self::Closed === $this;
    }
}
