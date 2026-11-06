<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Shared\Domain\Money;

/**
 * The invoicing of a firm, or of all firms, at a glance, in the home currency.
 * Overdue is split by how many days past the due date: up to 30, 31 to 60 and more.
 */
final readonly class InvoicingSummary
{
    /**
     * @param string|null                $firmId       the firm it covers, null for all
     * @param list<Debtor>               $debtors      who owes most first
     * @param list<PendingExport>        $pending      of the previous month
     * @param array{Money, Money, Money} $overdueByAge
     */
    public function __construct(
        public ?string $firmId,
        public MonthInvoiced $thisMonth,
        public MonthInvoiced $lastMonth,
        public Money $unpaid,
        public int $unpaidCount,
        public Money $overdue,
        public int $overdueCount,
        public array $overdueByAge,
        public array $debtors,
        public array $pending,
    ) {
    }
}
