<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\PaymentRefused;
use App\Invoicing\Application\PaymentState;

/**
 * A payment of an advance refunded or recorded by mistake is cancelled: its tax
 * document goes to the trash while the customer never got it, and stays for a
 * corrective document once they did.
 */
final class CancelledAdvancePaymentTest extends AdvanceTestCase
{
    public function testARefundBeforeDeliveryTrashesTheTaxDocument(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00');

        $this->cancel->cancel($payment->id());

        $document = $this->onlyTaxDocumentOf($advance);
        self::assertTrue($document->isTrashed());
        self::assertSame('Platba, ke které byl vystaven, byla zrušena.', $document->deletedReason());
        self::assertSame(PaymentState::Unpaid, PaymentState::of($this->reloaded($advance)->payable(), $this->payments->paidOf($this->reloaded($advance))));
    }

    public function testARefundAfterDeliveryKeepsTheTaxDocument(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00');
        $this->delivered->mark($this->onlyTaxDocumentOf($advance)->id());

        $this->cancel->cancel($payment->id());

        $document = $this->onlyTaxDocumentOf($advance);
        self::assertFalse($document->isTrashed());
        self::assertTrue($this->payments->get($payment->id())->isCancelled());
    }

    public function testCancellingTwiceTrashesOnce(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00');
        $this->cancel->cancel($payment->id());
        $trashedAt = $this->onlyTaxDocumentOf($advance)->deletedAt();

        $this->cancel->cancel($payment->id());

        self::assertEquals($trashedAt, $this->onlyTaxDocumentOf($advance)->deletedAt());
    }

    public function testCancellingOnePaymentLeavesTheOtherTaxDocument(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $first = $this->pay($advance, '605.00');
        $this->pay($advance, '605.00');

        $this->cancel->cancel($first->id());

        self::assertSame([true, false], array_map(static fn (\App\Invoicing\Domain\Invoice $document): bool => $document->isTrashed(), $this->taxDocumentsOf($advance)));
    }

    public function testANewPaymentAfterARefundGetsANewDocument(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $this->cancel->cancel($this->pay($advance, '1210.00')->id());

        $this->pay($advance, '1210.00', '2026-11-20');

        self::assertSame(['D820260001', 'D820260002'], array_map(static fn (\App\Invoicing\Domain\Invoice $document): string => $document->number(), $this->taxDocumentsOf($advance)));
    }

    public function testATaxDocumentOfACancelledPaymentStaysInTheTrash(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $this->cancel->cancel($this->pay($advance, '1210.00')->id());

        try {
            $this->trash->restore($this->onlyTaxDocumentOf($advance)->id());
            self::fail('Expected the restore to be refused.');
        } catch (PaymentRefused $refused) {
            self::assertSame(PaymentRefused::CANCELLED, $refused->reason);
        }
        self::assertTrue($this->onlyTaxDocumentOf($advance)->isTrashed());
    }

    public function testATaxDocumentTrashedWhileItsPaymentStandsComesBack(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $this->pay($advance, '1210.00');
        $this->trash->trash($this->onlyTaxDocumentOf($advance)->id(), 'Sent by mistake');

        $this->trash->restore($this->onlyTaxDocumentOf($advance)->id());

        self::assertFalse($this->onlyTaxDocumentOf($advance)->isTrashed());
    }

    public function testRestoringAKeptTaxDocumentOfACancelledPaymentChangesNothing(): void
    {
        $advance = $this->advance([$this->line('1000.00')]);
        $payment = $this->pay($advance, '1210.00');
        $this->delivered->mark($this->onlyTaxDocumentOf($advance)->id());
        $this->cancel->cancel($payment->id());
        $version = $this->onlyTaxDocumentOf($advance)->version();

        $this->trash->restore($this->onlyTaxDocumentOf($advance)->id());

        self::assertSame($version, $this->onlyTaxDocumentOf($advance)->version());
    }
}
