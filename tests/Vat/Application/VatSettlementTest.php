<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Application;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Vat\Application\PaymentView;
use Foresi\Vat\Application\PeriodState;
use Foresi\Vat\Application\SettlementPeriod;
use Foresi\Vat\Application\VatSettlement;
use Foresi\Vat\Contract\VatPayment;
use PHPUnit\Framework\TestCase;

/**
 * Which period each movement to or from the tax office pays, never by its
 * amount: November 2026 expects 1,890 and is due on 28 December, December
 * is due on 25 January 2027, January on 25 February.
 */
final class VatSettlementTest extends TestCase
{
    public function testAPaymentGoesToTheOldestUnsettledPeriodWhoseWindowHoldsIt(): void
    {
        [$paid, $unassigned] = VatSettlement::assign($this->periods(), [$this->out('m1', '2026-12-20', 1_500)], []);

        self::assertSame(['m1'], $this->ids($paid['2026-11']));
        self::assertSame([[], [], []], [$paid['2026-12'], $paid['2027-01'], $unassigned]);
        self::assertFalse($paid['2026-11'][0]->manual);
        self::assertSame('2026-11', $paid['2026-11'][0]->period);
    }

    public function testTwoPaymentsAddUpInOnePeriod(): void
    {
        [$paid] = VatSettlement::assign($this->periods(), [$this->out('m2', '2026-12-27', 890), $this->out('m1', '2026-12-20', 1_000)], []);

        self::assertSame(['m1', 'm2'], $this->ids($paid['2026-11']), 'Oldest first; the second finds November paid and adds to it.');
    }

    public function testALatePaymentGoesToTheOldestUnsettledPeriod(): void
    {
        [$paid] = VatSettlement::assign($this->periods(), [$this->out('m1', '2027-01-20', 1_890)], []);

        self::assertSame([['m1'], []], [$this->ids($paid['2026-11']), $paid['2026-12']]);
    }

    public function testAPaymentAfterTheWindowOfAPeriodGoesToTheNextOne(): void
    {
        [$paid] = VatSettlement::assign($this->periods(), [$this->out('m1', '2027-01-26', 1_890)], []);

        self::assertSame([[], ['m1']], [$paid['2026-11'], $this->ids($paid['2026-12'])], 'November\'s window closes on 25 January, the due date of December.');
    }

    public function testThePaymentOnTheLastDayOfTheWindowStillCounts(): void
    {
        [$paid] = VatSettlement::assign($this->periods(), [$this->out('m1', '2027-01-25', 1_890)], []);

        self::assertSame(['m1'], $this->ids($paid['2026-11']));
    }

    public function testAPaymentOnTheLastDayOfAPeriodPaysNoneOfItsOwn(): void
    {
        [$paid, $unassigned] = VatSettlement::assign($this->periods(), [$this->out('m1', '2026-11-30', 1_890)], []);

        self::assertSame([[], ['m1']], [$paid['2026-11'], $this->ids($unassigned)], 'A payment pays a period only after it ended.');
    }

    public function testAPeriodOfNothingOrAMarkedOneTakesNoPayment(): void
    {
        $periods = [
            new SettlementPeriod('2026-10', $this->day('2026-10-31'), $this->czk(0), $this->day('2026-12-28')),
            new SettlementPeriod('2026-11', $this->day('2026-11-30'), $this->czk(1_890), $this->day('2027-01-25'), true),
            new SettlementPeriod('2026-12', $this->day('2026-12-31'), $this->czk(-500), $this->day('2027-02-25')),
            new SettlementPeriod('2027-01', $this->day('2027-01-31'), $this->czk(100), $this->day('2027-03-25')),
        ];

        [$paid, $unassigned] = VatSettlement::assign($periods, [$this->out('m1', '2026-12-20', 1_890), $this->out('m2', '2027-02-10', 100)], []);

        self::assertSame([[], [], [], ['m2'], ['m1']], [$paid['2026-10'], $paid['2026-11'], $paid['2026-12'], $this->ids($paid['2027-01']), $this->ids($unassigned)]);
    }

    public function testAPaymentWithoutAPeriodInItsWindowAddsToTheLatestPaidPeriodWhoseWindowHoldsIt(): void
    {
        // November and December are paid; a third payment on 20 January fits both windows and goes to the latest.
        [$paid] = VatSettlement::assign($this->periods(), [$this->out('a', '2026-12-20', 1_890), $this->out('b', '2027-01-05', 500), $this->out('c', '2027-01-20', 10)], []);

        self::assertSame([['a'], ['b', 'c']], [$this->ids($paid['2026-11']), $this->ids($paid['2026-12'])]);
    }

    public function testAPaymentOutsideEveryWindowStaysUnassigned(): void
    {
        [$paid, $unassigned] = VatSettlement::assign($this->periods(), [$this->out('a', '2026-12-20', 1_890), $this->out('b', '2027-06-01', 100)], []);

        self::assertSame([['a'], ['b']], [$this->ids($paid['2026-11']), $this->ids($unassigned)]);
        self::assertNull($unassigned[0]->period);
    }

    public function testARefundGoesToTheOldestUnsettledPeriodToGetBack(): void
    {
        $periods = [
            new SettlementPeriod('2026-11', $this->day('2026-11-30'), $this->czk(1_890), $this->day('2027-01-25')),
            new SettlementPeriod('2026-12', $this->day('2026-12-31'), $this->czk(-3_000), $this->day('2027-02-25')),
            new SettlementPeriod('2027-01', $this->day('2027-01-31'), $this->czk(-100), $this->day('2027-03-25')),
        ];

        [$paid, $unassigned] = VatSettlement::assign($periods, [$this->in('r1', '2027-02-10', 3_000), $this->in('r2', '2027-02-11', 100), $this->in('r3', '2026-12-31', 5), $this->in('r4', '2027-04-01', 5)], []);

        self::assertSame([[], ['r1'], ['r2']], [$paid['2026-11'], $this->ids($paid['2026-12']), $this->ids($paid['2027-01'])]);
        self::assertSame(['r3', 'r4'], $this->ids($unassigned), 'None before December ended; none once every refund is settled.');
        self::assertSame(['refund_account'], $paid['2026-12'][0]->unverified);
        self::assertSame(-300_000, $paid['2026-12'][0]->signed()->minor());
    }

    public function testAnotherSymbolIsNeverMatchedOnItsOwn(): void
    {
        $other = new VatPayment('o1', $this->day('2026-12-20'), $this->czk(1_890), false, VatPayment::OTHER);

        [$paid, $unassigned] = VatSettlement::assign($this->periods(), [$other], []);

        self::assertSame([[], ['o1']], [$paid['2026-11'], $this->ids($unassigned)]);
        self::assertSame([], $unassigned[0]->unverified);
    }

    public function testAPersonsAssignmentWinsAndNoVatPaymentIsLeftOut(): void
    {
        $payments = [$this->out('m1', '2026-12-20', 1_890), $this->out('m2', '2026-12-21', 10), $this->out('m3', '2026-12-22', 10), $this->out('m4', '2026-12-23', 10)];

        [$paid, $unassigned] = VatSettlement::assign($this->periods(), $payments, ['m1' => '2027-01', 'm2' => null, 'm4' => '2025-01']);

        self::assertSame([['m3'], [], ['m1'], ['m4']], [$this->ids($paid['2026-11']), $paid['2026-12'], $this->ids($paid['2027-01']), $this->ids($unassigned)]);
        self::assertTrue($paid['2027-01'][0]->manual);
        self::assertTrue($unassigned[0]->manual, 'A period that is gone leaves the movement for a person to place again.');
        self::assertFalse($paid['2026-11'][0]->manual);
    }

    public function testAManualPaymentSettlesItsPeriodBeforeTheAutomaticOnesAreMatched(): void
    {
        [$paid] = VatSettlement::assign($this->periods(), [$this->out('auto', '2026-12-20', 1_890), $this->out('hand', '2027-01-30', 1_890)], ['hand' => '2026-11']);

        self::assertSame([['hand', 'auto'], []], [$this->ids($paid['2026-11']), $paid['2026-12']], 'November is paid by hand, so the automatic one adds to it as the latest paid period in its window.');
    }

    public function testTheStateOfAPeriod(): void
    {
        $today = $this->day('2027-01-05');

        self::assertSame(PeriodState::Settled, PeriodState::of(true, true, $this->day('2026-12-28'), $today));
        self::assertSame(PeriodState::NotYetPaid, PeriodState::of(false, true, $this->day('2027-01-04'), $today));
        self::assertSame(PeriodState::ReturnEntered, PeriodState::of(false, true, $this->day('2027-01-05'), $today), 'Due today is not yet past.');
        self::assertSame(PeriodState::Estimated, PeriodState::of(false, false, $this->day('2027-01-05'), $today));
        self::assertSame(PeriodState::Estimated, PeriodState::of(false, false, null, $today), 'A period of nothing is never unpaid.');
        self::assertSame(PeriodState::NotYetPaid, PeriodState::of(false, false, new \DateTimeImmutable('2027-01-04 23:30', new \DateTimeZone('UTC')), new \DateTimeImmutable('2027-01-05', new \DateTimeZone('Europe/Prague'))));
    }

    public function testAPaymentViewOutgoingKeepsItsSign(): void
    {
        $view = new PaymentView($this->out('m1', '2026-12-20', 1_500), '2026-11', false);

        self::assertSame([150_000, []], [$view->signed()->minor(), $view->unverified]);
    }

    /**
     * @return list<SettlementPeriod>
     */
    private function periods(): array
    {
        return [
            new SettlementPeriod('2026-11', $this->day('2026-11-30'), $this->czk(1_890), $this->day('2027-01-25')),
            new SettlementPeriod('2026-12', $this->day('2026-12-31'), $this->czk(500), $this->day('2027-02-25')),
            new SettlementPeriod('2027-01', $this->day('2027-01-31'), $this->czk(0), $this->day('2027-03-25')),
        ];
    }

    private function out(string $id, string $on, int $czk): VatPayment
    {
        return new VatPayment($id, $this->day($on), $this->czk($czk), false, VatPayment::PAYABLE);
    }

    private function in(string $id, string $on, int $czk): VatPayment
    {
        return new VatPayment($id, $this->day($on), $this->czk($czk), true, VatPayment::REFUND);
    }

    /**
     * @param list<PaymentView> $views
     *
     * @return list<string>
     */
    private function ids(array $views): array
    {
        return array_map(static fn (PaymentView $view): string => $view->payment->id, $views);
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('Europe/Prague'));
    }

    private function czk(int $czk): Money
    {
        return Money::ofMinor($czk * 100, Currency::of('CZK'));
    }
}
