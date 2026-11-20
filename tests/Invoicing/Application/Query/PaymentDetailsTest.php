<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\Query;

use Foresi\Invoicing\Application\Query\PaymentDetails;
use Foresi\Invoicing\Application\Query\PaymentsNotAllowed;
use Foresi\Invoicing\Application\UnknownPayment;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\PaymentAllocation;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;

final class PaymentDetailsTest extends PaymentReadTestCase
{
    public function testAPaymentComesWithTheInvoicesItPays(): void
    {
        $invoice = $this->invoice();
        $payment = $this->payment('2026-11-12', [$invoice->id() => '1210'], method: PaymentMethod::Cash, source: PaymentSource::Manual, note: 'Paid at the counter');

        $row = $this->details()->of($payment->id());

        self::assertSame($payment->id(), $row->id);
        self::assertSame('2026-11-12', $row->receivedOn->format('Y-m-d'));
        self::assertSame(121000, $row->amount->minor());
        self::assertSame('Paid at the counter', $row->note);
        self::assertCount(1, $row->allocations);
        self::assertSame([$invoice->id(), $invoice->number(), 121000], [$row->allocations[0]->invoiceId, $row->allocations[0]->invoiceNumber, $row->allocations[0]->amount->minor()]);
    }

    public function testTheDifferenceWrittenOffIsShownApartFromTheAmount(): void
    {
        $invoice = $this->invoice();
        $payment = $this->payment(amount: '1000');
        $this->em->persist(new PaymentAllocation($payment, $this->em->find(Invoice::class, $invoice->id()) ?? throw new \LogicException('No invoice.'), Money::of('1000', Currency::CZK), Money::of('0.50', Currency::CZK)));
        $this->em->flush();

        $allocation = $this->details()->of($payment->id())->allocations[0];

        self::assertSame(100000, $allocation->amount->minor());
        self::assertSame(50, $allocation->settlement->minor());
    }

    public function testACancelledPaymentIsReadWithoutAllocations(): void
    {
        $payment = $this->payment(amount: '100', cancelled: true);

        $row = $this->details()->of($payment->id());

        self::assertNotNull($row->cancelledAt);
        self::assertSame([], $row->allocations);
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testAnUnknownPaymentIsNotFound(): void
    {
        foreach ([Id::generate(), 'not-an-id', ''] as $id) {
            try {
                $this->details()->of($id);
                self::fail('Payment "'.$id.'" was found.');
            } catch (UnknownPayment) {
            }
        }
    }

    public function testGuestsAndNobodyAreRefusedBeforeAnythingIsLooked(): void
    {
        $payment = $this->payment(amount: '100');

        $this->assertRefusedForGuestAndNobody(fn (): \Foresi\Invoicing\Application\Query\PaymentRow => $this->details()->of($payment->id()));
        // Refused, not "not found", even for a payment that does not exist.
        $this->signIn($this->guest);
        $this->expectException(PaymentsNotAllowed::class);
        $this->details()->of(Id::generate());
    }

    public function testAMemberReadsAPayment(): void
    {
        $payment = $this->payment(amount: '100');
        $this->signIn($this->member);

        self::assertSame($payment->id(), $this->details()->of($payment->id())->id);
    }

    private function details(): PaymentDetails
    {
        return new PaymentDetails($this->access(), $this->queries());
    }
}
