<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application\Event;

/**
 * Told by the outermost transaction how it ended, so recorded events are
 * released after a commit and dropped after a rollback. A nested transaction
 * never tells it.
 */
interface EventOutbox
{
    public function committed(): void;

    public function rolledBack(): void;
}
