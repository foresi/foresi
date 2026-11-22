<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Application;

use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Vat\Support\VatTestCase;
use Foresi\Vat\Application\AssignPaymentInput;
use Foresi\Vat\Application\ClearReturnAmountInput;
use Foresi\Vat\Application\PeriodState;
use Foresi\Vat\Application\PeriodWrites;
use Foresi\Vat\Application\ReopenPeriodInput;
use Foresi\Vat\Application\ResetPaymentInput;
use Foresi\Vat\Application\SetReturnAmountInput;
use Foresi\Vat\Application\SettlePeriodInput;
use Foresi\Vat\Application\VatRefused;
use Foresi\Vat\Application\VatStore;
use Foresi\Vat\Domain\PaymentAssignment;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * What people decide about the periods: the return amount, the settled mark
 * and where a movement goes, each recorded as vat.period.changed, and only
 * for a period the firm has.
 */
final class PeriodWritesTest extends VatTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-12-10 10:00:00', 'UTC'));
        $this->stretch(VatStatus::NotRegistered, null, null);
        $this->stretch(VatStatus::Registered, VatPeriodLength::Month, '2026-11-12');
    }

    public function testAReturnAmountIsEnteredReplacedAndCleared(): void
    {
        $detail = $this->writes()->setReturnAmount(new SetReturnAmountInput(' 2026-11 ', ' 2000.50 '));

        self::assertSame(['2026-11', 200_050, 200_050, 0, PeriodState::ReturnEntered], [$detail->period->period->key, $detail->period->returnAmount?->minor(), $detail->period->expected->minor(), $detail->period->result->minor(), $detail->period->state]);

        $refund = $this->writes()->setReturnAmount(new SetReturnAmountInput('2026-11', '-300'));
        self::assertSame(-30_000, $refund->period->expected->minor());

        $cleared = $this->writes()->clearReturnAmount(new ClearReturnAmountInput('2026-11'));
        self::assertSame([null, 0, PeriodState::Estimated], [$cleared->period->returnAmount, $cleared->period->expected->minor(), $cleared->period->state]);
        self::assertNull($this->store()->entry('2026-11'), 'An entry with nothing in it is removed.');
        self::assertSame([['2026-11', ['what' => 'return_amount', 'period' => '2026-11', 'movement' => null]], ['2026-11', ['what' => 'return_amount', 'period' => '2026-11', 'movement' => null]], ['2026-11', ['what' => 'return_amount', 'period' => '2026-11', 'movement' => null]]], $this->events('vat.period.changed'));
        $this->refused('not_found', null, fn (): \Foresi\Vat\Application\PeriodDetail => $this->writes()->clearReturnAmount(new ClearReturnAmountInput('2026-11')));
    }

    public function testAnAmountMustBeADecimalNumber(): void
    {
        foreach (['', 'abc', '1.234', '1,5', '12345678901234'] as $amount) {
            $this->refused('amount_invalid', 'amount', fn (): \Foresi\Vat\Application\PeriodDetail => $this->writes()->setReturnAmount(new SetReturnAmountInput('2026-11', $amount)));
        }
        self::assertSame(123_456_789_012_300, $this->writes()->setReturnAmount(new SetReturnAmountInput('2026-11', '1234567890123'))->period->expected->minor());
    }

    public function testAPeriodTheFirmDoesNotHaveIsRefused(): void
    {
        $this->refused('not_found', null, fn (): \Foresi\Vat\Application\PeriodDetail => $this->writes()->setReturnAmount(new SetReturnAmountInput('2026-10', '1')));
        $this->refused('key_invalid', 'key', fn (): \Foresi\Vat\Application\PeriodDetail => $this->writes()->settle(new SettlePeriodInput('November')));
        $this->refused('key_invalid', 'period', fn (): \Foresi\Vat\Application\PaymentAssignmentView => $this->writes()->assign(new AssignPaymentInput('m1', 'November')));
        $this->refused('not_found', null, fn (): \Foresi\Vat\Application\PaymentAssignmentView => $this->writes()->assign(new AssignPaymentInput('m1', '2026-10')));
        self::assertSame([], $this->events('vat.period.changed'));
    }

    public function testAManualSettleAndReopen(): void
    {
        $settled = $this->writes()->settle(new SettlePeriodInput('2026-11', '  Paid from a savings account.  '));

        self::assertSame(PeriodState::Settled, $settled->period->state);
        self::assertSame([$this->member->user()->id(), 'Paid from a savings account.', '2026-12-10 10:00'], [$settled->period->mark?->by, $settled->period->mark?->note, $settled->period->mark?->at->format('Y-m-d H:i')]);

        $again = $this->writes()->settle(new SettlePeriodInput('2026-11'));
        self::assertNull($again->period->mark?->note, 'Marking again replaces the note.');

        $reopened = $this->writes()->reopen(new ReopenPeriodInput('2026-11'));
        self::assertSame([PeriodState::Estimated, null], [$reopened->period->state, $reopened->period->mark]);
        self::assertNull($this->store()->entry('2026-11'));
        self::assertSame(['settled', 'settled', 'reopened'], array_map(static fn (array $event): mixed => $event[1]['what'], $this->events('vat.period.changed')));
        $this->refused('not_found', null, fn (): \Foresi\Vat\Application\PeriodDetail => $this->writes()->reopen(new ReopenPeriodInput('2026-11')));
    }

    public function testReopeningKeepsTheReturnAmountAndClearingKeepsTheMark(): void
    {
        $this->writes()->setReturnAmount(new SetReturnAmountInput('2026-11', '100'));
        $this->writes()->settle(new SettlePeriodInput('2026-11'));
        $this->writes()->reopen(new ReopenPeriodInput('2026-11'));
        self::assertSame(10_000, $this->store()->entry('2026-11')?->returnAmount()?->minor());

        $this->writes()->settle(new SettlePeriodInput('2026-11'));
        $this->writes()->clearReturnAmount(new ClearReturnAmountInput('2026-11'));
        self::assertNotNull($this->store()->entry('2026-11')->settledAt(), 'Clearing the amount keeps the mark.');
    }

    public function testANoteIsAtMost500Characters(): void
    {
        $this->refused('note_too_long', 'note', fn (): \Foresi\Vat\Application\PeriodDetail => $this->writes()->settle(new SettlePeriodInput('2026-11', str_repeat('é', 501))));
        self::assertSame(str_repeat('é', 500), $this->writes()->settle(new SettlePeriodInput('2026-11', str_repeat('é', 500)))->period->mark?->note);
    }

    public function testAPaymentIsAssignedMovedMarkedAsNoVatAndGivenBack(): void
    {
        $assigned = $this->writes()->assign(new AssignPaymentInput(' m1 ', '2026-11'));
        self::assertSame(['m1', '2026-11', false], [$assigned->movementId, $assigned->period, $assigned->automatic]);

        Clock::set(new MockClock('2026-12-11 08:00:00', 'UTC'));
        $this->signIn($this->owner);
        $this->writes()->assign(new AssignPaymentInput('m1', null));
        $row = $this->store()->assignment('m1');
        self::assertInstanceOf(PaymentAssignment::class, $row);
        self::assertSame([null, '2026-12-11 08:00', $this->owner->user()->id()], [$row->periodKey(), $row->assignedAt()->format('Y-m-d H:i'), $row->assignedBy()]);

        $reset = $this->writes()->reset(new ResetPaymentInput('m1'));
        self::assertSame(['m1', null, true], [$reset->movementId, $reset->period, $reset->automatic]);
        self::assertNull($this->store()->assignment('m1'));
        self::assertSame([
            ['2026-11', ['what' => 'payment_assigned', 'period' => '2026-11', 'movement' => 'm1']],
            ['m1', ['what' => 'payment_assigned', 'period' => null, 'movement' => 'm1']],
            ['m1', ['what' => 'payment_automatic', 'period' => null, 'movement' => 'm1']],
        ], $this->events('vat.period.changed'));
        $this->refused('not_found', null, fn (): \Foresi\Vat\Application\PaymentAssignmentView => $this->writes()->reset(new ResetPaymentInput('m1')));
    }

    public function testAMovementIdHas1To64Characters(): void
    {
        $this->refused('movement_id_invalid', 'movementId', fn (): \Foresi\Vat\Application\PaymentAssignmentView => $this->writes()->assign(new AssignPaymentInput('  ', null)));
        $this->refused('movement_id_invalid', 'movementId', fn (): \Foresi\Vat\Application\PaymentAssignmentView => $this->writes()->reset(new ResetPaymentInput(str_repeat('a', 65))));
        self::assertSame(str_repeat('a', 64), $this->writes()->assign(new AssignPaymentInput(str_repeat('a', 64), null))->movementId);
    }

    public function testAGuestIsRefused(): void
    {
        $this->signIn($this->guest);

        $this->refused('forbidden', null, fn (): \Foresi\Vat\Application\PeriodDetail => $this->writes()->settle(new SettlePeriodInput('2026-11')));
        $this->refused('forbidden', null, fn (): \Foresi\Vat\Application\PaymentAssignmentView => $this->writes()->assign(new AssignPaymentInput('m1', null)));
        $this->refused('forbidden', null, fn (): \Foresi\Vat\Application\PaymentAssignmentView => $this->writes()->reset(new ResetPaymentInput('m1')));
    }

    private function writes(): PeriodWrites
    {
        return self::getContainer()->get(PeriodWrites::class);
    }

    private function store(): VatStore
    {
        return self::getContainer()->get(VatStore::class);
    }

    private function refused(string $reason, ?string $field, \Closure $work): void
    {
        try {
            $work();
            self::fail('Expected a refusal '.$reason.'.');
        } catch (VatRefused $refused) {
            self::assertSame([$reason, $field], [$refused->reason(), $refused->field()]);
        }
    }
}
