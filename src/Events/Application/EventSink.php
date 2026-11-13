<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Application;

use App\Events\Application\Query\EventRow;

/**
 * Takes each event the relay numbers, right after its position is set and in the
 * relay's transaction, so what a sink stores is committed with the position or
 * not at all. A sink that throws rolls the whole batch back; the rows stay
 * unnumbered and the next run tries them again. A sink that implements
 * ResetInterface is reset at the start of each batch.
 */
interface EventSink
{
    /**
     * @param string $tenantId the organisation the event belongs to
     */
    public function accept(string $tenantId, EventRow $row): void;
}
