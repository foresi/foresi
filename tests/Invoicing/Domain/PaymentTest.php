<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Domain;

use Foresi\Invoicing\Domain\Payment;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentTest extends TestCase
{
    public function testAPositiveAmountIsAPayment(): void
    {
        $payment = $this->payment('0.01');

        self::assertSame(1, $payment->amount()->minor());
        self::assertFalse($payment->isCancelled());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notPositive(): iterable
    {
        yield 'zero' => ['0.00'];
        yield 'negative' => ['-0.01'];
    }

    #[DataProvider('notPositive')]
    public function testAnAmountThatIsNotPositiveIsRefused(string $amount): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('A payment is a positive amount.'));

        $this->payment($amount);
    }

    private function payment(string $amount): Payment
    {
        $firm = new LegalEntity('Example Firm', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');

        return new Payment($firm, new \DateTimeImmutable('2026-11-20'), Money::of($amount, Currency::CZK), PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', null, new \DateTimeImmutable('2026-11-20 10:00'));
    }
}
