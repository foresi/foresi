<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Application;

use App\Shared\Domain\Currency;

/**
 * Time chosen for an invoice, from the dialog to the issue: the firm and the
 * client, the currency of every rate, and the lines with their entries.
 */
final readonly class TimeSelection
{
    /**
     * @param list<SelectedLine> $lines
     */
    public function __construct(
        public string $firmId,
        public string $clientId,
        public Currency $currency,
        public array $lines,
    ) {
    }
}
