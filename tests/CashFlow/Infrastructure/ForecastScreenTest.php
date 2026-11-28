<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Infrastructure;

use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\Forecasts;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowFlag;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Infrastructure\Screen\ForecastScreen;
use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;
use Foresi\Tests\CashFlow\Support\FakeSource;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The items of a cell in the sheet: a flow past its date but still awaited
 * reads as not yet paid or not yet received, in a neutral badge, never as
 * overdue or red.
 */
final class ForecastScreenTest extends CashFlowTestCase
{
    public function testTheAwaitedBadgeIsNeutralAndSaysNotYetPaid(): void
    {
        $this->clock->modify('2026-12-29 10:00:00');
        $locales = self::getContainer()->get(LocaleSwitcher::class);
        $locales->setLocale('cs');

        $out = $this->items(FlowDirection::Out, [FlowFlag::Estimated, FlowFlag::Awaited]);
        $in = $this->items(FlowDirection::In, [FlowFlag::Awaited]);

        self::assertSame([['label' => 'Zatím nezaplaceno', 'tone' => 'gray']], $out['flags'], 'An estimate says so in its own badge already.');
        self::assertSame([['label' => 'Zatím nepřišlo', 'tone' => 'gray']], $in['flags']);
        self::assertNotContains('red', array_column([...$out['flags'], ...$in['flags']], 'tone'));
        self::assertSame('Odhad daní, pojištění a DPH', $out['certainty']);

        $locales->setLocale('en');
        self::assertSame([['label' => 'Not yet paid', 'tone' => 'gray']], $this->items(FlowDirection::Out, [FlowFlag::Estimated, FlowFlag::Awaited])['flags']);
        self::assertSame([['label' => 'Not yet received', 'tone' => 'gray']], $this->items(FlowDirection::In, [FlowFlag::Awaited])['flags']);
    }

    public function testAnOverdueFlowStillReadsOverdue(): void
    {
        $this->clock->modify('2026-12-29 10:00:00');
        self::getContainer()->get(LocaleSwitcher::class)->setLocale('en');

        self::assertSame([['label' => 'Overdue', 'tone' => 'amber']], $this->items(FlowDirection::Out, [FlowFlag::Estimated])['flags']);
    }

    /**
     * The one item of a flow dated 2026-12-28 in the first period.
     *
     * @param list<FlowFlag> $flags
     *
     * @return array{label: string, certainty: string, certaintyTone: string, source: ?string, day: string, flags: list<array{label: string, tone: string}>, amount: string, czk: ?string}
     */
    private function items(FlowDirection $direction, array $flags): array
    {
        $container = self::getContainer();
        $flow = new ExpectedFlow('test.estimate', new FlowSubject('tax_payment', 'vat-2026-11', '/api/v1/example/vat-2026-11'), $direction, Money::of('1890.00', Currency::of('CZK')), new \DateTimeImmutable('2026-12-28', new \DateTimeZone('Europe/Prague')), FlowCategory::Vat, FlowCertainty::Estimate, 'VAT 2026-11', $flags);
        $forecasts = new Forecasts($container->get(CashFlowAccess::class), $container->get(CashFlowStore::class), $container->get(InstanceClock::class), $container->get(ExchangeRates::class), new NullLogger(), [new FakeSource('test.estimate', [$flow])], []);
        $period = $forecasts->of(null, 1)->periods[0];

        $items = $container->get(ForecastScreen::class)->items($period, $direction, FlowCategory::Vat)['items'];
        self::assertCount(1, $items);

        return $items[0];
    }
}
