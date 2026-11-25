<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract;

/**
 * The minutes logged on a project as other modules read them, such as Sales for
 * the hours logged on the project of an hourly deal line. Only time the person
 * signed in sees counts, as in the time report: an entry in the trash, or on a
 * task in the trash, does not, and a timer that still runs counts nothing yet.
 */
interface TimeTotals
{
    /**
     * @param \DateTimeImmutable|null $from the first day counted; null for all the time
     */
    public function minutes(string $projectId, ?\DateTimeImmutable $from = null, bool $billableOnly = false): int;
}
