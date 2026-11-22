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
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Contract\FirmVatStatuses;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\CashFlow\Support\FakeActualSource;
use Foresi\Tests\Vat\Support\VatTestCase;
use Foresi\Vat\Application\PaymentView;
use Foresi\Vat\Application\PeriodState;
use Foresi\Vat\Application\PeriodView;
use Foresi\Vat\Application\VatAccess;
use Foresi\Vat\Application\VatPeriods;
use Foresi\Vat\Application\VatRules;
use Foresi\Vat\Application\VatStore;
use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\PlannedVatSource;
use Foresi\Vat\Contract\PossibleDuplicate;
use Foresi\Vat\Contract\VatDocumentSource;
use Foresi\Vat\Contract\VatPayments;
use Foresi\Vat\Contract\VatPaymentSource;
use Foresi\Vat\Contract\VatSubject;
use Foresi\Vat\Domain\PaymentAssignment;
use Foresi\Vat\Domain\PeriodEntry;
use Foresi\Vat\Domain\VatSettings;
use Foresi\Vat\Integration\CashFlow\BankVatPayments;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * The periods with what they expect, what pays them and their state. Martin
 * is a monthly payer from 12 November 2026; his November gives 1,890 to pay,
 * due on 28 December. The office account is set, and today is 5 January
 * 2027 unless stated. A movement never matches by its amount.
 */
final class VatPeriodSettlementTest extends VatTestCase
{
    use BuildsMartinsVat;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2027-01-05 10:00:00', 'UTC'));
        $this->stretch(VatStatus::NotRegistered, null, null);
        $this->stretch(VatStatus::Registered, VatPeriodLength::Month, '2026-11-12');
        $this->em->persist(new VatSettings(self::OFFICE, self::SYMBOL));
        $this->em->flush();
    }

    public function testASmallerPaymentSettlesThePeriodAndShowsTheDifference(): void
    {
        $this->paid('m1', '2026-12-20', 1_500);

        $november = $this->period('2026-11');

        self::assertSame(PeriodState::Settled, $november->state);
        self::assertSame([189_000, 189_000, 150_000, -39_000], [$november->result->minor(), $november->expected->minor(), $november->paid?->minor(), $november->difference?->minor()]);
        self::assertSame(['m1'], array_map(static fn (PaymentView $view): string => $view->payment->id, $november->payments));
    }

    public function testTwoPaymentsAddUp(): void
    {
        $this->paid('m1', '2026-12-20', 1_000);
        $this->paid('m2', '2026-12-27', 890);

        $november = $this->period('2026-11');

        self::assertSame([PeriodState::Settled, 189_000, 0], [$november->state, $november->paid?->minor(), $november->difference?->minor()]);
        self::assertCount(2, $november->payments);
    }

    public function testALatePaymentGoesToTheOldestUnsettledPeriod(): void
    {
        Clock::set(new MockClock('2027-01-21 10:00:00', 'UTC'));
        $this->paid('m1', '2027-01-20', 1_890);

        self::assertSame(PeriodState::Settled, $this->period('2026-11')->state);
    }

    public function testAMovementWithAnotherVariableSymbolIsNotMatched(): void
    {
        $this->paid('m1', '2026-12-20', 1_890, '999');
        $this->movements[] = new ActualFlow('banking.movements', new FlowSubject('bank_movement', 'elsewhere'), FlowDirection::Out, $this->czk(1_890), $this->day('2026-12-21'), counterpartyAccount: '123/0100', variableSymbol: self::SYMBOL);

        $list = $this->vat()->list('2026-11', '2026-12');

        self::assertSame(PeriodState::NotYetPaid, $list->periods[0]->state);
        self::assertSame([['m1', 'other', null, false]], array_map(static fn (PaymentView $view): array => [$view->payment->id, $view->payment->rule, $view->period, $view->manual], $list->unassigned), 'Another symbol to the office is listed for a person to place; another account is no VAT payment at all.');
    }

    public function testAnUnpaidPastPeriodIsNotYetPaid(): void
    {
        $list = $this->vat()->list('2026-11', '2027-01');

        self::assertSame([PeriodState::NotYetPaid, PeriodState::Estimated, PeriodState::Estimated], array_map(static fn (PeriodView $view): PeriodState => $view->state, $list->periods));
        self::assertNull($list->periods[0]->paid);
        self::assertNull($list->periods[0]->difference);
        self::assertSame(PeriodState::Estimated, $list->periods[2]->state, 'January expects nothing yet.');
    }

    public function testTheStateFollowsTheDayInPrague(): void
    {
        Clock::set(new MockClock('2026-12-28 22:59:00', 'UTC'));
        self::assertSame(PeriodState::Estimated, $this->period('2026-11')->state, '23:59 in Prague on the due date.');

        Clock::set(new MockClock('2026-12-28 23:30:00', 'UTC'));
        self::assertSame(PeriodState::NotYetPaid, $this->period('2026-11')->state, '00:30 in Prague on 29 December, though UTC is still the 28th.');
    }

    public function testARefundFromTheOfficeSettlesTheRefundPeriod(): void
    {
        Clock::set(new MockClock('2027-02-11 10:00:00', 'UTC'));
        $this->paid('m1', '2026-12-20', 1_890);
        $this->movements[] = new ActualFlow('banking.movements', new FlowSubject('bank_movement', 'r1'), FlowDirection::In, $this->czk(3_000), $this->day('2027-02-10'), counterpartyAccount: self::OFFICE, variableSymbol: '4400123');

        $december = $this->period('2026-12');

        self::assertSame([PeriodState::Settled, -300_000, -300_000, 0], [$december->state, $december->expected->minor(), $december->paid?->minor(), $december->difference?->minor()]);
        self::assertSame(['refund_account'], $december->payments[0]->unverified);
        self::assertSame(PeriodState::Settled, $this->period('2026-11')->state);
    }

    public function testAnUnpaidRefundIsNotYetPaidAfterItsExpectedDay(): void
    {
        Clock::set(new MockClock('2027-02-24 10:00:00', 'UTC'));
        self::assertSame(PeriodState::Estimated, $this->period('2026-12')->state, 'Expected on 24 February, 30 days after the due date.');

        Clock::set(new MockClock('2027-02-25 10:00:00', 'UTC'));
        self::assertSame(PeriodState::NotYetPaid, $this->period('2026-12')->state);
    }

    public function testAReturnAmountReplacesTheEstimateUntilPaid(): void
    {
        Clock::set(new MockClock('2026-12-10 10:00:00', 'UTC'));
        $entry = new PeriodEntry('2026-11');
        $entry->enterReturnAmount($this->czk(2_000));
        $this->em->persist($entry);
        $this->em->flush();

        $november = $this->period('2026-11');

        self::assertSame([PeriodState::ReturnEntered, 189_000, 200_000, 200_000], [$november->state, $november->result->minor(), $november->returnAmount?->minor(), $november->expected->minor()]);

        Clock::set(new MockClock('2026-12-21 10:00:00', 'UTC'));
        $this->paid('m1', '2026-12-20', 2_000);
        self::assertSame([PeriodState::Settled, 0], [$this->period('2026-11')->state, $this->period('2026-11')->difference?->minor()]);
    }

    public function testAManualMarkSettlesWithoutAPayment(): void
    {
        $entry = new PeriodEntry('2026-11');
        $entry->settle(new \DateTimeImmutable('2027-01-04 09:00'), 'user-1', 'Paid from a savings account.');
        $this->em->persist($entry);
        $this->em->flush();
        $this->paid('m1', '2026-12-20', 1_890);

        $list = $this->vat()->list('2026-11', '2026-12');

        self::assertSame(PeriodState::Settled, $list->periods[0]->state);
        self::assertSame(['user-1', 'Paid from a savings account.'], [$list->periods[0]->mark?->by, $list->periods[0]->mark?->note]);
        self::assertSame([[], null], [$list->periods[0]->payments, $list->periods[0]->paid]);
        self::assertSame(['m1'], array_map(static fn (PaymentView $view): string => $view->payment->id, $list->unassigned), 'A marked period takes no payment; December is not over before the movement.');
    }

    public function testAPaymentMovedToAnotherPeriodAndUnmatched(): void
    {
        $this->paid('m1', '2026-12-20', 1_890);
        $this->paid('m2', '2026-12-21', 50);
        $this->em->persist(new PaymentAssignment('m1', '2026-12', new \DateTimeImmutable('2027-01-04'), 'user-1'));
        $this->em->persist(new PaymentAssignment('m2', null, new \DateTimeImmutable('2027-01-04'), 'user-1'));
        $this->em->flush();

        $list = $this->vat()->list('2026-11', '2026-12');

        self::assertSame([PeriodState::NotYetPaid, PeriodState::Settled], [$list->periods[0]->state, $list->periods[1]->state]);
        self::assertTrue($list->periods[1]->payments[0]->manual);
        self::assertSame([], $list->unassigned, 'A movement that is no VAT payment is left out.');
    }

    public function testWithoutAnOfficeAccountNothingMatches(): void
    {
        $this->em->remove($this->em->find(VatSettings::class, VatSettings::ID));
        $this->em->flush();
        $this->paid('m1', '2026-12-20', 1_890);
        $asked = new \ArrayObject();

        $list = $this->vat(asked: $asked)->list('2026-11', '2026-11');

        self::assertSame([PeriodState::NotYetPaid, []], [$list->periods[0]->state, $list->unassigned]);
        self::assertSame([], $asked->getArrayCopy(), 'The bank is not asked.');
    }

    public function testThePaymentsAreAskedFromTheFirstPeriodToTodayAndTheRangeDoesNotChangeThem(): void
    {
        $this->paid('m1', '2026-12-20', 1_000);
        $this->paid('m2', '2026-12-27', 890);
        $asked = new \ArrayObject();

        $december = $this->vat(asked: $asked)->list('2026-12', '2026-12');

        self::assertSame([['2026-11-01', '2027-01-05', self::OFFICE, self::SYMBOL]], $asked->getArrayCopy());
        self::assertSame([PeriodState::Estimated, []], [$december->periods[0]->state, $december->unassigned], 'Both pay November though it is not listed.');
    }

    public function testAnEmptySymbolMatchesByTheAccountAlone(): void
    {
        $this->em->find(VatSettings::class, VatSettings::ID)?->change(self::OFFICE, '');
        $this->em->flush();
        $asked = new \ArrayObject();
        $this->paid('m1', '2026-12-20', 1_890, '42');

        $november = $this->vat(asked: $asked)->list('2026-11', '2026-11')->periods[0];

        self::assertNull($asked[0][3]);
        self::assertSame(PeriodState::Settled, $november->state);
    }

    public function testABankThatFailsIsNamedAndMatchesNothing(): void
    {
        $this->paid('m1', '2026-12-20', 1_890);
        $failing = new class implements VatPaymentSource {
            public function paymentsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, string $account, ?string $variableSymbol): VatPayments
            {
                throw new \RuntimeException('The bank broke.');
            }
        };
        $partial = new BankVatPayments(new RuleMovements([new FakeActualSource('banking.movements', $this->movements, fails: true)]));

        $list = $this->vat([$failing, $partial])->list('2026-11', '2026-11');

        self::assertSame(['purchasing.received_vat', VatPeriods::PAYMENT_SOURCE, 'banking.movements'], $list->unavailableSources);
        self::assertSame(PeriodState::NotYetPaid, $list->periods[0]->state);
    }

    public function testAZeroPeriodIsNeverNotYetPaid(): void
    {
        Clock::set(new MockClock('2027-03-30 10:00:00', 'UTC'));

        $january = $this->period('2027-01');

        self::assertSame([0, PeriodState::Estimated, null], [$january->expected->minor(), $january->state, $january->awaitedOn()]);
    }

    public function testAnActiveVatPlannedItemIsListedAsAPossibleDuplicate(): void
    {
        $planned = new class implements PlannedVatSource {
            public int $calls = 0;

            public function activeVatItems(): array
            {
                ++$this->calls;

                return [new PossibleDuplicate('p1', 'VAT', '/cash-flow/planned-costs/p1')];
            }
        };
        $broken = new class implements PlannedVatSource {
            public function activeVatItems(): array
            {
                throw new \RuntimeException('The forecast broke.');
            }
        };

        $list = $this->vat(planned: [$broken, $planned])->list('2026-11', '2026-11');
        $detail = $this->vat(planned: [$planned])->get('2026-11');

        self::assertSame([['p1', 'VAT', '/cash-flow/planned-costs/p1']], array_map(static fn (PossibleDuplicate $item): array => [$item->id, $item->name, $item->url], $list->duplicates));
        self::assertCount(1, $detail->list->duplicates);
        self::assertCount(1, $this->vat(planned: [$planned])->list('2027-02', '2027-02')->duplicates, 'Listed with no period in the range too.');
        $calls = $planned->calls;
        self::assertCount(14, $this->vat(planned: [$planned])->open($this->day('2027-12-31')));
        self::assertSame($calls, $planned->calls, 'The forecast never reads them.');
    }

    public function testTheForecastReadsThePeriodsToTheMonthAskedAtMost24MonthsAhead(): void
    {
        $periods = $this->vat();

        self::assertSame('2027-03', $periods->open($this->day('2027-03-31'))[4]->period->key);
        self::assertCount(5, $periods->open($this->day('2027-03-31')));
        self::assertSame('2029-01', $periods->open($this->day('2035-12-31'))[26]->period->key);
        self::assertCount(27, $periods->open($this->day('2035-12-31')));
    }
}
