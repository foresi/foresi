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
use Foresi\Vat\Application\PeriodWrites;
use Foresi\Vat\Application\ResetPaymentInput;
use Foresi\Vat\Application\SetReturnAmountInput;
use Foresi\Vat\Application\SettlePeriodInput;
use Foresi\Vat\Application\VatRefused;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * What people decide about the periods: the return amount, the settled mark
 * and where a movement goes, each recorded as vat.period.changed, and only
 * for a period the firm has.
 * The tests that need the Czech rules are in Integration/Country.
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

    public function testAPeriodTheFirmDoesNotHaveIsRefused(): void
    {
        $this->refused('not_found', null, fn (): \Foresi\Vat\Application\PeriodDetail => $this->writes()->setReturnAmount(new SetReturnAmountInput('2026-10', '1')));
        $this->refused('key_invalid', 'key', fn (): \Foresi\Vat\Application\PeriodDetail => $this->writes()->settle(new SettlePeriodInput('November')));
        $this->refused('key_invalid', 'period', fn (): \Foresi\Vat\Application\PaymentAssignmentView => $this->writes()->assign(new AssignPaymentInput('m1', 'November')));
        $this->refused('not_found', null, fn (): \Foresi\Vat\Application\PaymentAssignmentView => $this->writes()->assign(new AssignPaymentInput('m1', '2026-10')));
        self::assertSame([], $this->events('vat.period.changed'));
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
