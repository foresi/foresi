<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\AllocationRequest;
use Foresi\Invoicing\Application\PaymentRequest;
use Foresi\Invoicing\Application\RecordPayment;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class RecordPaymentTest extends InvoiceTestCase
{
    private RecordPayment $record;

    protected function setUp(): void
    {
        parent::setUp();
        // In Prague it is 21 November already.
        Clock::set(new MockClock('2026-11-20 23:30:00', 'UTC'));
        $container = self::getContainer();
        $this->record = $container->get(RecordPayment::class);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAnUnknownInvoiceIsNotFound(): void
    {
        $this->expectException(\Foresi\Invoicing\Application\UnknownInvoice::class);
        $this->record->record($this->request('100.00', [new AllocationRequest('01963c5e-0000-7000-8000-0000000000ff', $this->czk('100.00'))]));
    }

    /**
     * @param list<AllocationRequest> $allocations
     */
    private function request(
        string $amount,
        array $allocations,
        string $on = '2026-11-20',
        string $note = '',
        PaymentMethod $method = PaymentMethod::BankTransfer,
        PaymentSource $source = PaymentSource::Manual,
        ?string $movement = null,
    ): PaymentRequest {
        return new PaymentRequest(new \DateTimeImmutable($on), $this->czk($amount), $method, $source, $movement, $note, $allocations);
    }

    private function czk(string $amount): Money
    {
        return Money::of($amount, Currency::of('CZK'));
    }
}
