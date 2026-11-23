<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\TaxOnReceipt;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Whether a payment of an advance creates VAT and so needs a tax document (§20a,
 * §28(1)(d), §28(9)(d), §92a of the VAT Act), and the deadline when it does.
 */
final class AdvanceTaxationTest extends InvoiceTestCase
{
    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function deadlines(): iterable
    {
        yield 'within the month' => ['2026-11-05', false, '2026-11-20'];
        yield 'into the next month' => ['2026-11-25', false, '2026-12-10'];
        yield 'December into January' => ['2026-12-27', false, '2027-01-11'];
        yield 'cross-border, end of December' => ['2026-12-31', true, '2027-01-15'];
        yield 'cross-border, early January' => ['2027-01-02', true, '2027-02-15'];
        yield 'cross-border, February of a common year' => ['2027-02-10', true, '2027-03-15'];
    }

    #[DataProvider('deadlines')]
    public function testTheDeadlineRunsFromReceiptOrFromTheEndOfTheMonth(string $receivedOn, bool $crossBorder, string $deadline): void
    {
        $taxation = TaxOnReceipt::arises($crossBorder);

        self::assertSame($deadline, $taxation->deadline(new \DateTimeImmutable($receivedOn.' 23:30', new \DateTimeZone('Europe/Prague')))->format('Y-m-d'));
    }
}
