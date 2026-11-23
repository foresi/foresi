<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\Query;

use Foresi\Invoicing\Application\Query\PaymentDetails;
use Foresi\Invoicing\Application\Query\PaymentsNotAllowed;
use Foresi\Invoicing\Application\UnknownPayment;
use Foresi\Shared\Domain\Id;

final class PaymentDetailsTest extends PaymentReadTestCase
{
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
