<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Port: how many sends a user and a firm may ask for in a while.
 */
interface SendLimits
{
    /**
     * Takes one send from both budgets; false when either is spent.
     */
    public function take(?string $userId, string $firmId): bool;
}
