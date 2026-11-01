<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class TrashInvoiceTest extends InvoiceTestCase
{
    use ClockSensitiveTrait;

    public function testTrashRecordsWhoWhenAndWhy(): void
    {
        self::mockTime('2026-11-03 10:00:00');
        $invoice = $this->issue->issue($this->input());

        $this->trash->trash($invoice, '  Wrong customer ');
        $invoice = $this->reloaded($invoice);

        self::assertTrue($invoice->isTrashed());
        self::assertSame('Wrong customer', $invoice->deletedReason());
        self::assertSame('2026-11-03', $invoice->deletedAt()?->format('Y-m-d'));
        self::assertNull($invoice->deletedBy());
        self::assertSame('20260001', $invoice->number());
    }

    public function testTheAggregateRecordsWhoDeletedIt(): void
    {
        $invoice = $this->issue->issue($this->input());

        $invoice->trash(new \DateTimeImmutable('2026-11-03'), '01963c5e-0000-7000-8000-00000000000a', 'Wrong customer');

        self::assertSame('01963c5e-0000-7000-8000-00000000000a', $invoice->deletedBy());
    }

    public function testADeliveredInvoiceCannotBeTrashed(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->delivered->mark($invoice);

        $this->expectException(\DomainException::class);
        $this->trash->trash($invoice, 'Wrong customer');
    }

    public function testTrashNeedsAReasonAndRestoreKeepsTheNumber(): void
    {
        $invoice = $this->issue->issue($this->input());

        try {
            $this->trash->trash($invoice, '   ');
            self::fail('Expected a refusal.');
        } catch (\DomainException) {
            self::assertFalse($invoice->isTrashed());
        }

        $this->trash->trash($invoice, 'Wrong customer');
        $this->trash->restore($this->reloaded($invoice));
        $invoice = $this->reloaded($invoice);

        self::assertFalse($invoice->isTrashed());
        self::assertNull($invoice->deletedAt());
        self::assertNull($invoice->deletedBy());
        self::assertNull($invoice->deletedReason());
        self::assertSame('20260001', $invoice->number());
    }

    public function testAnInvoiceIsTrashedOnce(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->trash->trash($invoice, 'First reason');

        $this->expectException(\DomainException::class);
        $this->trash->trash($invoice, 'Second reason');
    }

    public function testATrashedInvoiceCannotBeDelivered(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->trash->trash($invoice, 'Wrong customer');

        $this->expectException(\DomainException::class);
        $this->delivered->mark($invoice);
    }

    public function testDeliveryIsRecordedOnce(): void
    {
        self::mockTime('2026-11-03 10:00:00');
        $invoice = $this->issue->issue($this->input());
        $this->delivered->mark($invoice);
        self::mockTime('2026-11-20 10:00:00');
        $this->delivered->mark($invoice);

        self::assertTrue($this->reloaded($invoice)->isDelivered());
        self::assertSame('2026-11-03', $this->reloaded($invoice)->deliveredAt()?->format('Y-m-d'));
    }
}
