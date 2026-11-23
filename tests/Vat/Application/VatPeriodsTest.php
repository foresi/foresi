<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Application;

use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Vat\Support\VatTestCase;
use Foresi\Vat\Application\PeriodList;
use Foresi\Vat\Application\VatRefused;

/**
 * What the VAT periods refuse whatever the country's rules: a range too long,
 * a month that cannot be read, a firm whose country has no rules, and a
 * guest. The periods themselves follow the Czech rules, in
 * Integration/Country.
 */
final class VatPeriodsTest extends VatTestCase
{
    public function testToBeyond24MonthsIsRefused(): void
    {
        $this->martin();

        self::assertSame('2028-11', $this->periods()->list(null, '2028-11')->to);
        $this->assertRefused('range_too_long', 'to', fn (): PeriodList => $this->periods()->list(null, '2028-12'));
    }

    public function testAMonthThatCannotBeReadIsRefused(): void
    {
        $this->assertRefused('from_invalid', 'from', fn (): PeriodList => $this->periods()->list('2026-13', null));
        $this->assertRefused('to_invalid', 'to', fn (): PeriodList => $this->periods()->list(null, 'next year'));
        $this->assertRefused('to_invalid', 'to', fn (): PeriodList => $this->periods()->list(null, '2027-Q5'));
        $this->assertRefused('range_invalid', 'from', fn (): PeriodList => $this->periods()->list('2027-02', '2027-01'));
        $this->assertRefused('from_invalid', 'from', fn (): PeriodList => $this->periods()->list('x2027-01', null));
        $this->assertRefused('to_invalid', 'to', fn (): PeriodList => $this->periods()->list(null, 'x2027-Q1'));
        self::assertSame('2027-01', $this->periods()->list('2027-01', '2027-01')->from);
    }

    public function testWithoutCountryRulesTheAnswerSaysWhy(): void
    {
        $this->martin();
        $this->firm->update($this->firm->name(), $this->firm->registrationNumber(), $this->firm->vatId(), new Address('Example Street 1', 'Example City', '811 01', 'SK'), $this->firm->vatStatus(), '');
        $this->em->flush();

        $list = $this->periods()->list(null, null);

        self::assertSame(PeriodList::COUNTRY_NOT_SUPPORTED, $list->reason);
        self::assertNull($list->country);
        self::assertSame([[], [], []], [$list->periods, $list->warnings, $list->unverified]);
    }

    public function testAGuestIsRefused(): void
    {
        $this->signIn($this->guest);

        $this->expectException(VatRefused::class);
        $this->expectExceptionMessageIsOrContains('Only the owners and the members');
        $this->periods()->list(null, null);
    }

    /**
     * Martin: not a payer from the start, a monthly payer from 12 November 2026.
     */
    private function martin(): void
    {
        $this->stretch(VatStatus::NotRegistered, null, null);
        $this->stretch(VatStatus::Registered, VatPeriodLength::Month, '2026-11-12');
    }

    private function assertRefused(string $reason, string $field, \Closure $work): void
    {
        try {
            $work();
            self::fail('Expected a refusal '.$reason.'.');
        } catch (VatRefused $refused) {
            self::assertSame([$reason, $field], [$refused->reason(), $refused->field()]);
        }
    }
}
