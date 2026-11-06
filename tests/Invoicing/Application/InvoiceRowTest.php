<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\InvoiceRow;
use App\Invoicing\Application\PaymentState;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What is still to pay on a line of the invoice list follows its corrections.
 */
#[CoversClass(InvoiceRow::class)]
final class InvoiceRowTest extends TestCase
{
    public function testWithoutCorrectionsTheRestOfThePayableIsToPay(): void
    {
        self::assertSame(71000, $this->row(50000)->remaining()->minor());
    }

    public function testACreditNoteBelowWhatWasPaidLeavesSomethingToRefund(): void
    {
        self::assertSame(-48400, $this->row(121000, Money::ofMinor(-48400, Currency::CZK))->remaining()->minor());
    }

    private function row(int $paid, ?Money $corrections = null): InvoiceRow
    {
        return new InvoiceRow(
            'id',
            '2026-0001',
            'Example Firm',
            'Example Customer',
            new \DateTimeImmutable('2026-11-20'),
            new \DateTimeImmutable('2026-12-04'),
            Money::ofMinor(121000, Currency::CZK),
            true,
            false,
            false,
            Money::ofMinor($paid, Currency::CZK),
            PaymentState::PartlyPaid,
            corrections: $corrections,
        );
    }
}
