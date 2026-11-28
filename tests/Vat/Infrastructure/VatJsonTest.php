<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Infrastructure;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Vat\Application\ScheduleAmount;
use Foresi\Vat\Contract\ScheduledVat;
use Foresi\Vat\Contract\VatSubject;
use Foresi\Vat\Infrastructure\Api\VatJson;
use PHPUnit\Framework\TestCase;

/**
 * A scheduled occurrence as REST and MCP write it: its CZK amount, the
 * amount in the schedule's own currency, and whether it is converted at
 * today's rate or has no rate.
 */
final class VatJsonTest extends TestCase
{
    public function testAScheduleShowsItsAmountInCzkAndInItsCurrency(): void
    {
        $schedule = new ScheduledVat('invoicing.recurring_vat', new VatSubject('recurring_invoice', '019a0000-0000-7000-8000-000000000001', null), 'Hosting (2026-12-01)', new \DateTimeImmutable('2026-12-01', new \DateTimeZone('Europe/Prague')), Money::ofMinor(21_000, Currency::of('EUR')));

        self::assertSame([
            'source' => 'invoicing.recurring_vat',
            'subject' => ['type' => 'recurring_invoice', 'id' => '019a0000-0000-7000-8000-000000000001', 'url' => null],
            'label' => 'Hosting (2026-12-01)',
            'tax_point_on' => '2026-12-01',
            'output' => ['amount_minor' => 525_000, 'currency' => 'CZK', 'minor_units' => 2],
            'output_in_currency' => ['amount_minor' => 21_000, 'currency' => 'EUR', 'minor_units' => 2],
            'fx_today' => true,
            'missing_czk' => false,
        ], VatJson::schedule(new ScheduleAmount($schedule, Money::ofMinor(525_000, Currency::of('CZK')), true)));
        self::assertSame([false, true], array_values(array_intersect_key(VatJson::schedule(new ScheduleAmount($schedule, Money::ofMinor(0, Currency::of('CZK')), false, true)), ['fx_today' => 0, 'missing_czk' => 0])));
    }
}
