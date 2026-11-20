<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

/**
 * Port: how many sends a user and the instance may ask for in a while.
 */
interface SendLimits
{
    /**
     * Takes one send from both budgets; false when either is spent.
     */
    public function take(?string $userId): bool;
}
