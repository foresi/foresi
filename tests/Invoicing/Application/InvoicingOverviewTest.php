<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\InvoicingOverview;
use Foresi\Invoicing\Application\MonthInvoiced;

/**
 * Today is 20 November 2026, so the last month is October.
 */
final class InvoicingOverviewTest extends AdvanceTestCase
{
    private InvoicingOverview $overview;

    protected function setUp(): void
    {
        parent::setUp();
        $this->overview = self::getContainer()->get(InvoicingOverview::class);
    }

    public function testNothingIssuedGivesZeros(): void
    {
        $summary = $this->overview->summary();

        self::assertFalse($this->overview->hasDocuments());
        self::assertSame([0, 0, 0, 0], $this->figures($summary->thisMonth));
        self::assertSame([0, 0, 0, 0], [$summary->unpaidCount, $summary->unpaid->minor(), $summary->overdueCount, $summary->overdue->minor()]);
        self::assertSame([], $summary->debtors);
        self::assertSame([], $summary->pending);
    }

    /**
     * @return array{int, int, int, int} count, base, VAT and total in minor units
     */
    private function figures(MonthInvoiced $month): array
    {
        return [$month->count, $month->base->minor(), $month->vat->minor(), $month->total->minor()];
    }
}
