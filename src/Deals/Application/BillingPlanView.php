<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application;

use Foresi\Shared\Domain\Money;

/**
 * The billing plan of a deal: its planned invoices, the oldest first, and what
 * of its one-off value is not planned yet, per currency.
 */
final readonly class BillingPlanView
{
    /**
     * @param list<BillingEntryView> $entries
     * @param array<string, Money>   $notPlanned by currency code; only a currency with something left
     */
    public function __construct(
        public array $entries = [],
        public array $notPlanned = [],
    ) {
    }

    /**
     * What the plan expects and nothing fulfils yet, one invoice each, per currency.
     *
     * @return array<string, Money>
     */
    public function notInvoiced(): array
    {
        $totals = [];
        foreach ($this->entries as $view) {
            if (BillingEntryView::INVOICED === $view->state || !$view->amount instanceof Money) {
                continue;
            }
            $code = $view->amount->currency()->value;
            $totals[$code] = isset($totals[$code]) ? $totals[$code]->plus($view->amount) : $view->amount;
        }
        ksort($totals);

        return $totals;
    }
}
