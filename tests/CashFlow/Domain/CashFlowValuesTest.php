<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\CashFlow\Domain;

use App\CashFlow\Contract\ExpectedFlow;
use App\CashFlow\Contract\FlowCategory;
use App\CashFlow\Contract\FlowCertainty;
use App\CashFlow\Contract\FlowDirection;
use App\CashFlow\Contract\FlowFlag;
use App\CashFlow\Contract\FlowSubject;
use App\CashFlow\Contract\ForecastPeriod;
use App\CashFlow\Contract\PeriodKind;
use App\CashFlow\Domain\Checkpoint;
use App\CashFlow\Domain\ExpectedDate;
use App\CashFlow\Domain\MatchRule;
use App\CashFlow\Domain\MinimumBalance;
use App\CashFlow\Domain\PlannedItem;
use App\CashFlow\Domain\PlannedItemTerms;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Domain\TenantId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The values of the cash-flow contract and domain on their own.
 */
final class CashFlowValuesTest extends TestCase
{
    public function testAFlowMovesWithAFlagOnceAndKeepsEverythingElse(): void
    {
        $flow = new ExpectedFlow('test.source', new FlowSubject('invoice', 'inv-1', '/api/v1/invoices/inv-1'), FlowDirection::In, Money::of('10.00', Currency::EUR), new \DateTimeImmutable('2026-11-10'), FlowCategory::Revenue, FlowCertainty::Document, 'FV-1', [FlowFlag::NoDueDate], 'contact-1');

        $moved = $flow->movedTo(new \DateTimeImmutable('2026-12-01'), FlowFlag::ExpectedDate)->movedTo(new \DateTimeImmutable('2026-12-02'), FlowFlag::ExpectedDate);

        self::assertSame('2026-12-02', $moved->expectedOn->format('Y-m-d'));
        self::assertSame([FlowFlag::NoDueDate, FlowFlag::ExpectedDate], $moved->flags);
        self::assertTrue($moved->hasFlag(FlowFlag::ExpectedDate));
        self::assertFalse($flow->hasFlag(FlowFlag::ExpectedDate));
        self::assertEquals([$flow->source, $flow->subject, $flow->direction, $flow->amount, $flow->category, $flow->certainty, $flow->label, $flow->counterpartyId], [$moved->source, $moved->subject, $moved->direction, $moved->amount, $moved->category, $moved->certainty, $moved->label, $moved->counterpartyId]);
        self::assertSame('2026-11-10', $flow->expectedOn->format('Y-m-d'));
    }

    public function testAFlowHasAPositiveAmount(): void
    {
        new ExpectedFlow('test.source', new FlowSubject('invoice', 'zero'), FlowDirection::Out, Money::of('0', Currency::CZK), new \DateTimeImmutable(), FlowCategory::Other, FlowCertainty::Estimate, 'Zero');

        $this->expectException(\InvalidArgumentException::class);
        new ExpectedFlow('test.source', new FlowSubject('invoice', 'minus'), FlowDirection::Out, Money::of('-0.01', Currency::CZK), new \DateTimeImmutable(), FlowCategory::Other, FlowCertainty::Estimate, 'Minus');
    }

    public function testAPeriodIsKeyedByItsIsoWeekOrMonth(): void
    {
        // The ISO year of 1 January 2027 is 2026.
        self::assertSame('2026-W53', new ForecastPeriod(PeriodKind::Week, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-01-03'))->key());
        self::assertSame('2027-01', new ForecastPeriod(PeriodKind::Month, new \DateTimeImmutable('2027-01-04'), new \DateTimeImmutable('2027-01-31'))->key());
    }

    public function testTheCategoriesAreTheFixedList(): void
    {
        self::assertSame(['revenue', 'purchases', 'rent', 'software', 'payroll', 'taxes', 'insurance', 'vat', 'loans', 'owner_draws', 'other'], FlowCategory::values());
    }

    public function testAMatchRuleHasNoNegativeTolerance(): void
    {
        self::assertTrue(MatchRule::none()->isEmpty());
        self::assertFalse(MatchRule::of(null, '1', null)->isEmpty());
        self::assertFalse(MatchRule::of('1/0100', null, null)->isEmpty());
        self::assertSame(0, MatchRule::of(null, null, 0)->toleranceMinor());

        $this->expectException(\InvalidArgumentException::class);
        MatchRule::of(null, null, -1);
    }

    public function testTermsHaveANameAndAPositiveAmount(): void
    {
        $terms = static fn (string $name, string $amount): PlannedItemTerms => new PlannedItemTerms($name, FlowCategory::Rent, Money::of($amount, Currency::CZK), new \DateTimeImmutable('2026-11-20'), null, null, true, MatchRule::none());
        self::assertSame('Rent', $terms('Rent', '0.01')->name);

        foreach ([[' ', '1.00'], ['Rent', '0.00'], ['Rent', '-1.00']] as [$name, $amount]) {
            try {
                $terms($name, $amount);
                self::fail('Expected a refusal of '.$name.' '.$amount);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAMinimumBalanceIsInCzk(): void
    {
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $minimum = new MinimumBalance($firm, Money::of('100.00', Currency::CZK));
        self::assertSame(10000, $minimum->amount()->minor());

        $this->expectException(\InvalidArgumentException::class);
        $minimum->change(Money::of('100.00', Currency::EUR));
    }

    #[DataProvider('stampedOnPersist')]
    public function testTheTenantIsKnownOnlyOncePersisted(\Closure $tenantOfANewOne): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The tenant is stamped on persist.');
        $tenantOfANewOne();
    }

    /**
     * @return iterable<string, array{\Closure}>
     */
    public static function stampedOnPersist(): iterable
    {
        yield 'planned item' => [static fn (): TenantId => new \ReflectionClass(PlannedItem::class)->newInstanceWithoutConstructor()->tenantId()];
        yield 'checkpoint' => [static fn (): TenantId => new \ReflectionClass(Checkpoint::class)->newInstanceWithoutConstructor()->tenantId()];
        yield 'expected date' => [static fn (): TenantId => new \ReflectionClass(ExpectedDate::class)->newInstanceWithoutConstructor()->tenantId()];
        yield 'minimum balance' => [static fn (): TenantId => new \ReflectionClass(MinimumBalance::class)->newInstanceWithoutConstructor()->tenantId()];
    }

    public function testAnItemsDayIsTheSameCalendarDayInAnyZone(): void
    {
        $day = PlannedItem::inZone(new \DateTimeImmutable('2026-11-30 23:30:00', new \DateTimeZone('UTC')), new \DateTimeZone('Europe/Prague'));

        self::assertSame('2026-11-30 00:00:00 Europe/Prague', $day->format('Y-m-d H:i:s e'));
    }
}
