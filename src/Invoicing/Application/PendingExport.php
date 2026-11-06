<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * The documents of a firm in a month not passed to the accountant yet: never
 * exported, or changed after their export.
 *
 * @param string $month YYYY-MM
 */
final readonly class PendingExport
{
    public function __construct(public string $firmId, public string $firmName, public string $month, public int $count)
    {
    }
}
