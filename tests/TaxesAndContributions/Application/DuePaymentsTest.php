<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Application;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Application\DuePayments;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\AuthorityAccountView;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Contract\LevyPayment;
use Foresi\TaxesAndContributions\Contract\ProfileView;
use Foresi\Tests\TaxesAndContributions\Support\FakeLevyPayments;
use Foresi\Tests\TaxesAndContributions\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Which due payments the bank paid (B4): a movement that meets the match
 * rule of the authority pays one payment it could pay on its day, the one it
 * is on time for with the same amount first, else the oldest; an unpaid
 * payment past its due day is overdue.
 */
final class DuePaymentsTest extends TestCase
{
    private const string ZONE = 'Europe/Prague';

    public function testAMatchingBankMovementPaysThePayment(): void
    {
        $bank = new FakeLevyPayments([new LevyPayment(Authority::Social, 'm1', $this->day('2026-10-28'), $this->czk(5_005))]);

        [$stated, $failed] = new DuePayments([$bank])->stated([$this->advance(Authority::Social, '2026-10', '2026-10-31')], $this->profile(), $this->day('2026-11-20'));

        self::assertFalse($failed);
        self::assertSame([DuePayment::STATE_PAID, 'm1', '2026-10-28'], [$stated[0]->state, $stated[0]->paidBy, $stated[0]->paidOn?->format('Y-m-d')]);
        self::assertSame(['123-456789/0100', '7805121234'], [$stated[0]->account, $stated[0]->variableSymbol], 'The payment takes the match rule of its authority.');
        self::assertSame([['2026-10-01', '2026-11-20', ['social', 'health']]], $bank->asked);
    }

    public function testAnUnpaidPastAdvanceIsOverdueAndOneToComeIsExpected(): void
    {
        [$stated] = new DuePayments([new FakeLevyPayments()])->stated([
            $this->advance(Authority::Social, '2026-10', '2026-10-31'),
            $this->advance(Authority::Social, '2026-11', '2026-11-30'),
            $this->advance(Authority::Social, '2026-11', '2026-11-20'),
        ], $this->profile(), $this->day('2026-11-20'));

        self::assertSame([DuePayment::STATE_OVERDUE, DuePayment::STATE_EXPECTED, DuePayment::STATE_EXPECTED], array_map(static fn (DuePayment $payment): string => $payment->state, $stated), 'Due today is not overdue yet.');
    }

    public function testWithoutTheAuthorityAccountNoMovementPaysAndTheBankIsNotAsked(): void
    {
        $bank = new FakeLevyPayments([new LevyPayment(Authority::IncomeTax, 'm1', $this->day('2026-06-10'), $this->czk(5_005))]);

        [$stated] = new DuePayments([$bank])->stated([$this->advance(Authority::IncomeTax, '2026-06', '2026-06-15')], new ProfileView([], [], []), $this->day('2026-11-20'));

        self::assertSame([DuePayment::STATE_OVERDUE, false], [$stated[0]->state, $stated[0]->hasMatchRule()]);
        self::assertSame([], $bank->asked);
    }

    public function testAMovementOfAnotherAuthorityOrBeforeThePaymentCouldBePaidPaysNothing(): void
    {
        $bank = new FakeLevyPayments([
            new LevyPayment(Authority::Health, 'm1', $this->day('2026-10-10'), $this->czk(5_005)),
            new LevyPayment(Authority::Social, 'm2', $this->day('2026-09-30'), $this->czk(5_005)),
            new LevyPayment(Authority::Social, 'm3', $this->day('2026-10-10'), $this->czk(5_005), incoming: true),
        ]);

        [$stated] = new DuePayments([$bank])->stated([$this->advance(Authority::Social, '2026-10', '2026-10-31')], $this->profile(), $this->day('2026-11-20'));

        self::assertSame(DuePayment::STATE_OVERDUE, $stated[0]->state);
    }

    public function testAMovementPaysOnePaymentTheOneOfItsAmountFirstElseTheOneDueFirst(): void
    {
        $payments = [
            $this->advance(Authority::Health, '2026-09', '2026-10-08', '2026-09-01', 3_306),
            $this->advance(Authority::Health, '2026-10', '2026-11-08', '2026-10-01', 4_000),
            $this->advance(Authority::Health, '2026-11', '2026-12-08', '2026-11-01', 3_306),
        ];
        $bank = new FakeLevyPayments([
            new LevyPayment(Authority::Health, 'late', $this->day('2026-10-20'), $this->czk(4_000)),
            new LevyPayment(Authority::Health, 'any', $this->day('2026-11-05'), $this->czk(1_000)),
            new LevyPayment(Authority::Health, 'spare', $this->day('2026-11-06'), $this->czk(3_306)),
            new LevyPayment(Authority::Health, 'more', $this->day('2026-11-07'), $this->czk(3_306)),
        ]);

        [$stated] = new DuePayments([$bank])->stated($payments, $this->profile(), $this->day('2026-11-20'));

        // 4,000 pays the October advance it is on time for and of its amount; 1,000 the November one it is on time for;
        // 3,306 the September one of its amount, late; nothing is left for the last movement.
        self::assertSame(['spare', 'late', 'any'], array_map(static fn (DuePayment $payment): ?string => $payment->paidBy, $stated));
    }

    public function testALateMovementOfAnotherAmountPaysTheOldestDebt(): void
    {
        $bank = new FakeLevyPayments([new LevyPayment(Authority::Social, 'm1', $this->day('2026-11-10'), $this->czk(6_000))]);

        [$stated] = new DuePayments([$bank])->stated([
            $this->advance(Authority::Social, '2026-09', '2026-09-30'),
            $this->advance(Authority::Social, '2026-10', '2026-10-31'),
        ], $this->profile(), $this->day('2026-11-20'));

        self::assertSame(['m1', null], array_map(static fn (DuePayment $payment): ?string => $payment->paidBy, $stated));
    }

    public function testAnOverpaymentIsPaidByAnIncomingMovement(): void
    {
        $back = new DuePayment(Authority::Social, DuePayment::KIND_OVERPAYMENT, '2025', $this->day('2026-01-01'), $this->day('2026-06-11'), $this->czk(2_000));
        $bank = new FakeLevyPayments([new LevyPayment(Authority::Social, 'refund', $this->day('2026-07-01'), $this->czk(2_000), incoming: true)]);

        [$stated] = new DuePayments([$bank])->stated([$back], $this->profile(), $this->day('2026-11-20'));

        self::assertSame([DuePayment::STATE_PAID, 'refund'], [$stated[0]->state, $stated[0]->paidBy]);
    }

    public function testAFailingBankIsLoggedAndSaid(): void
    {
        $logger = new RecordingLogger();

        [$stated, $failed] = new DuePayments([new FakeLevyPayments(fails: true)], $logger)->stated([$this->advance(Authority::Social, '2026-10', '2026-10-31')], $this->profile(), $this->day('2026-11-20'));

        self::assertTrue($failed);
        self::assertSame(DuePayment::STATE_OVERDUE, $stated[0]->state);
        self::assertSame('error', $logger->records[0][0]);
        self::assertInstanceOf(\RuntimeException::class, $logger->records[0][1]['exception'] ?? null);
    }

    public function testNothingToPayOrNothingPayableYetAsksNoBank(): void
    {
        $bank = new FakeLevyPayments();

        self::assertSame([[], false], new DuePayments([$bank])->stated([], $this->profile(), $this->day('2026-11-20')));
        [$stated, $failed] = new DuePayments([$bank])->stated([$this->advance(Authority::Social, '2027-01', '2027-01-31')], $this->profile(), $this->day('2026-11-20'));

        self::assertSame([DuePayment::STATE_EXPECTED, false], [$stated[0]->state, $failed]);
        self::assertSame([], $bank->asked);
    }

    public function testAMovementOnTheFirstPayableDayOrOnTheDueDayCountsForThatPayment(): void
    {
        $bank = new FakeLevyPayments([
            new LevyPayment(Authority::Social, 'first', $this->day('2026-10-01'), $this->czk(5_005)),
            new LevyPayment(Authority::Health, 'due', $this->day('2026-10-31'), $this->czk(5_005)),
        ]);

        [$stated] = new DuePayments([$bank])->stated([
            $this->advance(Authority::Social, '2026-10', '2026-10-31'),
            $this->advance(Authority::Health, '2026-09', '2026-09-30'),
            $this->advance(Authority::Health, '2026-10', '2026-10-31'),
        ], $this->profile(), $this->day('2026-11-20'));

        self::assertSame(['first', null, 'due'], array_map(static fn (DuePayment $payment): ?string => $payment->paidBy, $stated), 'Paid on its due day is on time; the late September one waits.');
    }

    public function testTheSameAmountWinsOverBeingOnTime(): void
    {
        $bank = new FakeLevyPayments([new LevyPayment(Authority::Health, 'm1', $this->day('2026-11-06'), $this->czk(3_306))]);

        [$stated] = new DuePayments([$bank])->stated([
            $this->advance(Authority::Health, '2026-09', '2026-10-08', '2026-09-01', 3_306),
            $this->advance(Authority::Health, '2026-11', '2026-12-08', '2026-11-01', 4_000),
        ], $this->profile(), $this->day('2026-11-20'));

        self::assertSame(['m1', null], array_map(static fn (DuePayment $payment): ?string => $payment->paidBy, $stated));
    }

    public function testTheMovementsPayInTheOrderTheyWereBooked(): void
    {
        $bank = new FakeLevyPayments([
            new LevyPayment(Authority::Social, 'a-late', $this->day('2026-10-05'), $this->czk(5_005)),
            new LevyPayment(Authority::Social, 'b-early', $this->day('2026-09-29'), $this->czk(5_005)),
        ]);

        [$stated] = new DuePayments([$bank])->stated([$this->advance(Authority::Social, '2026-09', '2026-09-30')], $this->profile(), $this->day('2026-11-20'));

        self::assertSame('b-early', $stated[0]->paidBy);
    }

    public function testAPaymentPayableFromTodayAsksTheBank(): void
    {
        $bank = new FakeLevyPayments([new LevyPayment(Authority::Social, 'm1', $this->day('2026-11-01'), $this->czk(5_005))]);

        [$stated, $failed] = new DuePayments([$bank])->stated([$this->advance(Authority::Social, '2026-11', '2026-11-30')], $this->profile(), $this->day('2026-11-01'));

        self::assertSame([DuePayment::STATE_PAID, false], [$stated[0]->state, $failed]);
    }

    private function profile(): ProfileView
    {
        return new ProfileView([], [], [
            new AuthorityAccountView('social', '123-456789/0100', '7805121234'),
            new AuthorityAccountView('health', '1234567890/0300', '7805121234'),
        ]);
    }

    private function advance(Authority $authority, string $period, string $due, ?string $from = null, int $czk = 5_005): DuePayment
    {
        return new DuePayment($authority, DuePayment::KIND_ADVANCE, $period, $this->day($from ?? $period.'-01'), $this->day($due), $this->czk($czk));
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone(self::ZONE));
    }

    private function czk(int $czk): Money
    {
        return Money::ofMinor($czk * 100, Currency::CZK);
    }
}
