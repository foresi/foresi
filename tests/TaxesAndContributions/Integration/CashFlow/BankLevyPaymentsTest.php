<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Integration\CashFlow;

use Foresi\CashFlow\Application\RuleMovements;
use Foresi\CashFlow\Contract\ActualFlow;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\AuthorityAccountView;
use Foresi\TaxesAndContributions\Contract\LevyPayment;
use Foresi\TaxesAndContributions\Integration\CashFlow\BankLevyPayments;
use Foresi\Tests\CashFlow\Support\FakeActualSource;
use PHPUnit\Framework\TestCase;

/**
 * The bank movements that pay the levies (B4): those that meet an authority's
 * account and variable symbol, each paying one authority at most.
 */
final class BankLevyPaymentsTest extends TestCase
{
    public function testAMovementPaysTheFirstAuthorityItMeetsAndADocumentNone(): void
    {
        $day = new \DateTimeImmutable('2026-10-30');
        $money = Money::ofMinor(500_500, Currency::of('CZK'));
        $bank = new FakeActualSource('banking.movements', [
            new ActualFlow('banking.movements', new FlowSubject('bank_movement', 'm1'), FlowDirection::Out, $money, $day, counterpartyAccount: '123/0100', variableSymbol: '42'),
            new ActualFlow('banking.movements', new FlowSubject('bank_movement', 'm2'), FlowDirection::In, $money, $day, counterpartyAccount: '777/0300', variableSymbol: '9'),
            new ActualFlow('invoicing.invoices', new FlowSubject('invoice', 'i1'), FlowDirection::Out, $money, $day, counterpartyId: 'c1', counterpartyAccount: '123/0100', variableSymbol: '42'),
        ]);
        $accounts = [
            new AuthorityAccountView('social', '123/0100', '42'),
            new AuthorityAccountView('sickness', '123/0100', '42'),
            new AuthorityAccountView('unknown', '777/0300', ''),
            new AuthorityAccountView('health', '777/0300', ''),
        ];

        $payments = new BankLevyPayments(new RuleMovements([$bank]))->paymentsBetween($day, $day, $accounts)->payments;

        self::assertSame([['social', 'm1', false], ['health', 'm2', true]], array_map(static fn (LevyPayment $payment): array => [$payment->authority->value, $payment->id, $payment->incoming], $payments));
        self::assertSame(Authority::Health, $payments[1]->authority, 'An empty symbol asks only for the account.');
        self::assertSame([['2026-10-30', '2026-10-30']], $bank->asked);
    }

    public function testAFailingBankSourceIsSaidAndGivesNoPayment(): void
    {
        $day = new \DateTimeImmutable('2026-10-30');
        $movement = new ActualFlow('banking.movements', new FlowSubject('bank_movement', 'm1'), FlowDirection::Out, Money::ofMinor(500_500, Currency::of('CZK')), $day, counterpartyAccount: '123/0100', variableSymbol: '42');
        $finder = new RuleMovements([new FakeActualSource('banking.movements', [$movement], fails: true)]);

        $found = new BankLevyPayments($finder)->paymentsBetween($day, $day, [new AuthorityAccountView('social', '123/0100', '42')]);

        self::assertSame([[], ['banking.movements']], [$found->payments, $found->unavailable]);
    }
}
