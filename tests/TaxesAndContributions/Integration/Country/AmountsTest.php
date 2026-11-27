<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Integration\Country;

use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Application\Amounts;
use Foresi\TaxesAndContributions\Application\ProfileReads;
use Foresi\TaxesAndContributions\Contract\ActualAmount;
use Foresi\TaxesAndContributions\Contract\ActualAmountSource;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\AmountSum;
use Foresi\TaxesAndContributions\Contract\Subject;
use Foresi\Tests\ExchangeRate\Support\FakeRateSource;
use Foresi\Tests\TaxesAndContributions\Support\FakeAmountSource;
use Foresi\Tests\TaxesAndContributions\Support\TaxesTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

/**
 * The actual amounts on a cash basis count each payment on its day in the
 * time zone of the Czech rules.
 */
final class AmountsTest extends TaxesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $rates = new FakeRateSource();
        $rates->publish('2026-11-13', '25.000', '15.000');
        $rates->publish('2026-11-16', '26.000', '15.000');
        self::getContainer()->set(RateSource::class, $rates);
    }

    public function testThePaymentOnTheDayAfterPragueMidnightCountsOnThePragueDay(): void
    {
        // 23:30 UTC on 30 November is 00:30 on 1 December in Prague.
        Clock::set(new MockClock('2026-11-30 23:30:00', 'UTC'));
        $source = new FakeAmountSource('invoicing.payments', [
            $this->amount('invoicing.payments', 'december', AmountKind::Income, '2026-12-01', 10_000, 10_000),
        ]);

        $amounts = $this->amounts([$source, new FakeAmountSource('received-documents.payments', [])])->year(2026);

        self::assertSame('2026-12-01', $amounts->to->format('Y-m-d'));
        self::assertSame(['2026-01-01', '2026-12-01', 'Europe/Prague'], [$source->asked[0][0]->format('Y-m-d'), $source->asked[0][1]->format('Y-m-d'), $source->asked[0][0]->getTimezone()->getName()]);
        self::assertSame(['2026-12'], array_map(static fn (AmountSum $sum): string => $sum->month, $amounts->sums));
    }

    /**
     * @param list<ActualAmountSource> $sources
     */
    private function amounts(array $sources, ?LoggerInterface $logger = null): Amounts
    {
        $container = self::getContainer();

        return new Amounts($container->get(ProfileReads::class), $sources, $container->get(ExchangeRates::class), $container->get(InstanceClock::class), $logger ?? new NullLogger());
    }

    private function amount(string $source, string $id, AmountKind $kind, string $paidOn, int $gross, int $net, Currency $currency = Currency::CZK): ActualAmount
    {
        $zone = new \DateTimeZone('Europe/Prague');

        return new ActualAmount($source, new Subject('document', $id, '/api/v1/documents/'.$id), $kind, new \DateTimeImmutable($paidOn, $zone), Money::ofMinor($gross, $currency), Money::ofMinor($net, $currency), Money::ofMinor($gross - $net, $currency), new \DateTimeImmutable($paidOn, $zone), false);
    }
}
