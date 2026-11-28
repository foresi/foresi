<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Integration\CashFlow;

use Foresi\CashFlow\Application\RuleMovements;
use Foresi\CashFlow\Contract\ActualFlow;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\CashFlow\Support\FakeActualSource;
use Foresi\Vat\Contract\VatPayment;
use Foresi\Vat\Integration\CashFlow\BankVatPayments;
use PHPUnit\Framework\TestCase;

/**
 * The movements to and from the tax office, by the rule each meets: out with
 * the symbol pays, in from the account is a refund whatever its symbol, out
 * with another symbol is only listed. A document never pays the VAT.
 */
final class BankVatPaymentsTest extends TestCase
{
    private const string OFFICE = '705-77628031/0710';

    public function testEachMovementMeetsOneRule(): void
    {
        $bank = new FakeActualSource('banking.movements', [
            $this->movement('pay', FlowDirection::Out, self::OFFICE, '12345678'),
            $this->movement('back', FlowDirection::In, self::OFFICE, '4400'),
            $this->movement('other', FlowDirection::Out, self::OFFICE, '9'),
            $this->movement('elsewhere', FlowDirection::Out, '123/0100', '12345678'),
            new ActualFlow('invoicing.invoices', new FlowSubject('invoice', 'i1'), FlowDirection::In, Money::ofMinor(100, Currency::of('CZK')), new \DateTimeImmutable('2026-12-20'), counterpartyId: 'c1', counterpartyAccount: self::OFFICE, variableSymbol: '12345678'),
        ]);

        $found = new BankVatPayments(new RuleMovements([$bank]))->paymentsBetween(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2027-01-05'), self::OFFICE, '12345678');

        self::assertSame([['back', VatPayment::REFUND, true], ['other', VatPayment::OTHER, false], ['pay', VatPayment::PAYABLE, false]], array_map(static fn (VatPayment $payment): array => [$payment->id, $payment->rule, $payment->incoming], $found->payments));
        self::assertSame(['2026-12-20', 50_000], [$found->payments[0]->on->format('Y-m-d'), $found->payments[0]->amount->minor()]);
        self::assertSame([['2026-11-01', '2027-01-05']], $bank->asked);
    }

    public function testWithoutASymbolEveryPaymentToTheOfficePays(): void
    {
        $bank = new FakeActualSource('banking.movements', [$this->movement('pay', FlowDirection::Out, self::OFFICE, '9'), $this->movement('back', FlowDirection::In, self::OFFICE, null)]);

        $found = new BankVatPayments(new RuleMovements([$bank]))->paymentsBetween(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2027-01-05'), self::OFFICE, null);

        self::assertSame([['back', VatPayment::REFUND], ['pay', VatPayment::PAYABLE]], array_map(static fn (VatPayment $payment): array => [$payment->id, $payment->rule], $found->payments));
    }

    public function testAFailingBankIsNamed(): void
    {
        $bank = new FakeActualSource('banking.movements', [$this->movement('pay', FlowDirection::Out, self::OFFICE, '1')], fails: true);

        $found = new BankVatPayments(new RuleMovements([$bank]))->paymentsBetween(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2027-01-05'), self::OFFICE, '1');

        self::assertSame([[], ['banking.movements']], [$found->payments, $found->unavailable]);
    }

    private function movement(string $id, FlowDirection $direction, string $account, ?string $symbol): ActualFlow
    {
        return new ActualFlow('banking.movements', new FlowSubject('bank_movement', $id), $direction, Money::ofMinor(50_000, Currency::of('CZK')), new \DateTimeImmutable('2026-12-20'), counterpartyAccount: $account, variableSymbol: $symbol);
    }
}
