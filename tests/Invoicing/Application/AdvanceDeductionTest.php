<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\InvoiceFilter;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\Invoice;

/**
 * A final invoice deducts the customer's paid advances, one tax document at a
 * time, with negative lines per rate, and no document twice.
 */
final class AdvanceDeductionTest extends AdvanceTestCase
{
    public function testEachKindListsTheTabsThatMeanSomethingForIt(): void
    {
        self::assertSame([InvoiceFilter::ALL, InvoiceFilter::PAID, InvoiceFilter::UNPAID, InvoiceFilter::OVERDUE, InvoiceFilter::TO_REFUND, InvoiceFilter::TRASH], InvoiceFilter::tabsOf(DocumentKind::Invoice));
        self::assertSame([InvoiceFilter::ALL, InvoiceFilter::PAID, InvoiceFilter::UNPAID, InvoiceFilter::OVERDUE, InvoiceFilter::UNSETTLED, InvoiceFilter::TRASH], InvoiceFilter::tabsOf(DocumentKind::Advance));
        self::assertSame([InvoiceFilter::ALL, InvoiceFilter::SETTLED, InvoiceFilter::UNSETTLED, InvoiceFilter::TRASH], InvoiceFilter::tabsOf(DocumentKind::PaymentTaxDocument));
    }
}
