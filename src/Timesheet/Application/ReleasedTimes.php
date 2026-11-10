<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Application;

use App\Timesheet\Domain\ReleasedTime;

/**
 * The time released by invoices in the trash of the active tenant.
 */
interface ReleasedTimes
{
    /**
     * @return list<ReleasedTime>
     */
    public function ofInvoice(string $invoiceId): array;

    public function add(ReleasedTime $time): void;

    public function remove(ReleasedTime $time): void;
}
