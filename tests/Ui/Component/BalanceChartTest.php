<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Foresi\Ui\Component\BalanceChart;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * The geometry of the balance chart: round gridlines that hold zero, every
 * amount and the minimum, two columns per period from the baseline, and the
 * closing line through the centres; and the SVG it renders.
 */
final class BalanceChartTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const array LEGEND = ['in' => 'In', 'out' => 'Out', 'closing' => 'Closing', 'minimum' => 'Minimum'];

    /**
     * @param list<array{0: int, 1: int, 2: int}> $amounts in, out and closing of each period
     */
    private static function chart(array $amounts, ?int $minimum = null): BalanceChart
    {
        $chart = new BalanceChart(new LocaleSwitcher('en', []));
        $chart->label = 'Money in and out';
        $chart->legend = self::LEGEND;
        $chart->minimum = $minimum;
        $chart->periods = self::periods($amounts);
        $chart->validate();

        return $chart;
    }

    /**
     * @param list<array{0: int, 1: int, 2: int}> $amounts
     *
     * @return list<array<string, mixed>>
     */
    private static function periods(array $amounts): array
    {
        return array_map(static fn (array $a, int $i): array => [
            'label' => 'P'.$i, 'in' => $a[0], 'out' => $a[1], 'closing' => $a[2],
            'inText' => $a[0].' Kč', 'outText' => $a[1].' Kč', 'closingText' => $a[2].' Kč',
        ], $amounts, array_keys($amounts));
    }

    public function testTheAxisRunsFromZeroToARoundStepAboveTheHighest(): void
    {
        $chart = self::chart([[48176, 12000, 161176], [30000, 95000, 96176]]);

        $ticks = $chart->ticks();

        self::assertSame(['0', '50,000', '100,000', '150,000', '200,000'], array_column($ticks, 'text'));
        self::assertTrue($ticks[0]['zero']);
        self::assertEqualsWithDelta(BalanceChart::HEIGHT - BalanceChart::BOTTOM, $ticks[0]['y'], 0.01);
        self::assertEqualsWithDelta(BalanceChart::TOP, $ticks[4]['y'], 0.01);
    }

    public function testANegativeClosingAndTheMinimumWidenTheAxis(): void
    {
        $chart = self::chart([[10000, 60000, -35000]], minimum: 250000);

        $texts = array_column($chart->ticks(), 'text');

        self::assertSame('-100,000', $texts[0]);
        self::assertSame('300,000', $texts[array_key_last($texts)]);
        self::assertContains('0', $texts);
        $minimumY = $chart->minimumY();
        self::assertNotNull($minimumY);
        self::assertGreaterThan($chart->y(300000), $minimumY);
        self::assertLessThan($chart->y(0), $minimumY);
    }

    public function testEachPeriodHasTwoColumnsFromTheBaselineAndAPointOfItsClosing(): void
    {
        $chart = self::chart([[100, 50, 200], [0, 100, 100]]);
        $columns = $chart->columns();
        $base = $chart->y(0);

        self::assertSame(BalanceChart::LEFT, $columns[0]['x']);
        self::assertSame(BalanceChart::LEFT + BalanceChart::SLOT, $columns[1]['x']);
        self::assertEqualsWithDelta(BalanceChart::LEFT + BalanceChart::SLOT / 2, $columns[0]['centre'], 0.01);
        // The column of money in starts at the baseline, left of the centre, and is rounded at the top.
        self::assertNotNull($columns[0]['inPath']);
        self::assertStringStartsWith(\sprintf('M%d,%s V', BalanceChart::LEFT + BalanceChart::SLOT / 2 - BalanceChart::GAP / 2 - BalanceChart::BAR, rtrim(rtrim(\sprintf('%.1f', $base), '0'), '.')), $columns[0]['inPath']);
        self::assertStringContainsString(' Q', $columns[0]['inPath']);
        // Nothing in is no column at all.
        self::assertNull($columns[1]['inPath']);
        self::assertNotNull($columns[1]['outPath']);
        self::assertSame($chart->y(200), $columns[0]['closingY']);
        self::assertSame(BalanceChart::LEFT + 2 * BalanceChart::SLOT + BalanceChart::RIGHT, $chart->width());
    }

    public function testTheLineJoinsTheClosingsFromLeftToRight(): void
    {
        $chart = self::chart([[0, 0, 100], [0, 0, 300]]);

        self::assertMatchesRegularExpression('/^M116,[\d.]+ L188,[\d.]+$/', $chart->line());
    }

    public function testAFlatForecastStillHasAnAxis(): void
    {
        $chart = self::chart([[0, 0, 0]]);

        self::assertSame(['0', '1'], array_column($chart->ticks(), 'text'));
    }

    public function testRendersOneLabelledImageWithTheTooltipFiguresOfEachPeriod(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:BalanceChart', [
            'label' => 'Money in and out by month',
            'legend' => self::LEGEND,
            'minimum' => 50000,
            'minimumText' => '50 000 Kč',
            'periods' => [...self::periods([[48176, 12000, 161176]]), ['label' => 'Dec', 'in' => 0, 'out' => 90000, 'closing' => 40000, 'inText' => '0 Kč', 'outText' => '90 000 Kč', 'closingText' => '40 000 Kč', 'below' => true]],
        ]);

        self::assertStringContainsString('role="img" aria-label="Money in and out by month"', $html);
        self::assertSame(2, substr_count($html, 'data-balance-chart-target="period"'));
        self::assertStringContainsString('data-in="48176 Kč"', $html);
        self::assertSame(1, substr_count($html, 'data-below'));
        self::assertSame(1, substr_count($html, 'data-series="minimum"'));
        self::assertSame(1, substr_count($html, 'data-series="closing"'));
        self::assertSame(1, substr_count($html, 'data-series="in"'));
        self::assertSame(2, substr_count($html, 'data-series="out"'));
        self::assertStringContainsString('Minimum 50 000 Kč', $html);
    }

    public function testWithoutAMinimumThereIsNoDashedLine(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:BalanceChart', ['label' => 'Chart', 'legend' => self::LEGEND, 'periods' => self::periods([[1, 2, 3]])]);

        self::assertStringNotContainsString('data-series="minimum"', $html);
    }

    public function testRefusesAChartWithoutPeriodsOrWithNegativeFlows(): void
    {
        $this->expectException(\Throwable::class);
        $this->renderTwigComponent('Ui:BalanceChart', ['label' => 'Chart', 'legend' => self::LEGEND, 'periods' => self::periods([[-1, 2, 3]])]);
    }

    public function testRefusesAChartWithoutPeriods(): void
    {
        $this->expectException(\Throwable::class);
        $this->renderTwigComponent('Ui:BalanceChart', ['label' => 'Chart', 'legend' => self::LEGEND, 'periods' => []]);
    }
}
