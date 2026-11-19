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
    private function chart(array $amounts, ?int $minimum = null): BalanceChart
    {
        $chart = new BalanceChart(new LocaleSwitcher('en', []));
        $chart->label = 'Money in and out';
        $chart->legend = self::LEGEND;
        $chart->minimum = $minimum;
        $chart->periods = $this->periods($amounts);
        $chart->validate();

        return $chart;
    }

    /**
     * @param list<array{0: int, 1: int, 2: int}> $amounts
     *
     * @return list<array<string, mixed>>
     */
    private function periods(array $amounts): array
    {
        return array_map(static fn (array $a, int $i): array => [
            'label' => 'P'.$i, 'in' => $a[0], 'out' => $a[1], 'closing' => $a[2],
            'inText' => $a[0].' Kč', 'outText' => $a[1].' Kč', 'closingText' => $a[2].' Kč',
        ], $amounts, array_keys($amounts));
    }

    public function testTheAxisRunsFromZeroToARoundStepAboveTheHighest(): void
    {
        $chart = $this->chart([[48176, 12000, 161176], [30000, 95000, 96176]]);

        $ticks = $chart->ticks();

        self::assertSame(['0', '50,000', '100,000', '150,000', '200,000'], array_column($ticks, 'text'));
        self::assertTrue($ticks[0]['zero']);
        self::assertEqualsWithDelta(BalanceChart::HEIGHT - BalanceChart::BOTTOM, $ticks[0]['y'], 0.01);
        self::assertEqualsWithDelta(BalanceChart::TOP, $ticks[4]['y'], 0.01);
    }

    public function testANegativeClosingAndTheMinimumWidenTheAxis(): void
    {
        $chart = $this->chart([[10000, 60000, -35000]], minimum: 250000);

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
        $chart = $this->chart([[100, 50, 200], [0, 100, 100]]);
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
        $chart = $this->chart([[0, 0, 100], [0, 0, 300]]);

        self::assertMatchesRegularExpression('/^M116,[\d.]+ L188,[\d.]+$/', $chart->line());
    }

    public function testAFlatForecastStillHasAnAxis(): void
    {
        $chart = $this->chart([[0, 0, 0]]);

        self::assertSame(['0', '1'], array_column($chart->ticks(), 'text'));
    }

    public function testRendersOneLabelledImageWithTheTooltipFiguresOfEachPeriod(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:BalanceChart', [
            'label' => 'Money in and out by month',
            'legend' => self::LEGEND,
            'minimum' => 50000,
            'minimumText' => '50 000 Kč',
            'periods' => [...$this->periods([[48176, 12000, 161176]]), ['label' => 'Dec', 'in' => 0, 'out' => 90000, 'closing' => 40000, 'inText' => '0 Kč', 'outText' => '90 000 Kč', 'closingText' => '40 000 Kč', 'below' => true]],
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
        $html = (string) $this->renderTwigComponent('Ui:BalanceChart', ['label' => 'Chart', 'legend' => self::LEGEND, 'periods' => $this->periods([[1, 2, 3]])]);

        self::assertStringNotContainsString('data-series="minimum"', $html);
    }

    public function testRefusesAChartWithoutPeriodsOrWithNegativeFlows(): void
    {
        $this->expectException(\Throwable::class);
        $this->renderTwigComponent('Ui:BalanceChart', ['label' => 'Chart', 'legend' => self::LEGEND, 'periods' => $this->periods([[-1, 2, 3]])]);
    }

    public function testRefusesAChartWithoutPeriods(): void
    {
        $this->expectException(\Throwable::class);
        $this->renderTwigComponent('Ui:BalanceChart', ['label' => 'Chart', 'legend' => self::LEGEND, 'periods' => []]);
    }

    /**
     * @return iterable<string, array{0: int, 1: list<string>}>
     */
    public static function steps(): iterable
    {
        yield 'a step of 1 × 10ⁿ' => [400, ['0', '100', '200', '300', '400']];
        yield 'a step of 2 × 10ⁿ' => [800, ['0', '200', '400', '600', '800']];
        yield 'a step of 5 × 10ⁿ, not 2.5' => [1000, ['0', '500', '1,000']];
        yield 'a step of 5 × 10ⁿ' => [2000, ['0', '500', '1,000', '1,500', '2,000']];
        yield 'a step of 10 × 10ⁿ' => [2400, ['0', '1,000', '2,000', '3,000']];
    }

    /**
     * @param list<string> $texts
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('steps')]
    public function testTheStepIsOneTwoOrFiveTimesAPowerOfTen(int $high, array $texts): void
    {
        self::assertSame($texts, array_column($this->chart([[0, 0, $high]])->ticks(), 'text'));
    }

    public function testTheAxisStartsAtZeroWhenEveryAmountIsAboveIt(): void
    {
        self::assertSame(['0', '100', '200', '300', '400'], array_column($this->chart([[100, 100, 400]])->ticks(), 'text'));
    }

    public function testTheAxisEndsAtZeroWhenEveryAmountIsBelowIt(): void
    {
        $ticks = $this->chart([[0, 0, -500]])->ticks();

        self::assertSame(['-600', '-400', '-200', '0'], array_column($ticks, 'text'));
        self::assertSame([false, false, false, true], array_column($ticks, 'zero'));
    }

    public function testAnAxisAroundZeroPutsZeroInTheMiddle(): void
    {
        $chart = $this->chart([[1000, 0, -1000]]);

        self::assertSame(['-1,000', '-500', '0', '500', '1,000'], array_column($chart->ticks(), 'text'));
        self::assertSame(122.0, $chart->y(0));
        self::assertSame([232.0, 177.0, 122.0, 67.0, 12.0], array_column($chart->ticks(), 'y'));
    }

    public function testAPointIsPlacedToATenthOfAUnit(): void
    {
        // 0 to 3,000 over 220 units of plot: 1,000 is two thirds down from the top.
        self::assertSame(158.7, $this->chart([[0, 0, 2400]])->y(1000));
    }

    public function testTheColumnsAreExactPathsEitherSideOfTheCentre(): void
    {
        $columns = $this->chart([[400, 200, 0]])->columns();

        self::assertSame(116.0, $columns[0]['centre']);
        self::assertSame('M94,232 V15 Q94,12 97,12 H111 Q114,12 114,15 V232 Z', $columns[0]['inPath']);
        self::assertSame('M118,232 V125 Q118,122 121,122 H135 Q138,122 138,125 V232 Z', $columns[0]['outPath']);
        self::assertSame(232.0, $columns[0]['closingY']);
    }

    public function testALowColumnIsRoundedNoMoreThanItIsHigh(): void
    {
        // 1 of 400 is 0.55 units of plot, its top rounded to 231.5: half a unit high, and half a unit of rounding.
        $columns = $this->chart([[1, 0, 400]])->columns();

        self::assertSame('M94,232 V232 Q94,231.5 94.5,231.5 H113.5 Q114,231.5 114,232 V232 Z', $columns[0]['inPath']);
        self::assertNull($columns[0]['outPath']);
    }

    public function testTheChartIsAsWideAsItsPeriods(): void
    {
        $chart = $this->chart(array_fill(0, 24, [0, 0, 1]));

        self::assertSame(BalanceChart::LEFT + 24 * BalanceChart::SLOT + BalanceChart::RIGHT, $chart->width());
        self::assertSame(BalanceChart::HEIGHT, $chart->height());
        self::assertFalse($chart->columns()[0]['below']);
    }

    public function testRefusesMoreThanTwentyFourPeriods(): void
    {
        $this->expectExceptionMessageIsOrContains('A balance chart shows 1 to 24 periods.');
        $this->chart(array_fill(0, 25, [0, 0, 1]));
    }

    public function testRefusesALegendWithoutMoneyIn(): void
    {
        $chart = new BalanceChart(new LocaleSwitcher('en', []));
        $chart->label = 'Chart';
        $chart->legend = ['out' => 'Out', 'closing' => 'Closing'];
        $chart->periods = $this->periods([[1, 2, 3]]);

        $this->expectExceptionMessageIsOrContains('The legend of a balance chart');
        $chart->validate();
    }

    public function testAMinimumNeedsItsNameInTheLegendAndNoneDoesNot(): void
    {
        $chart = new BalanceChart(new LocaleSwitcher('en', []));
        $chart->label = 'Chart';
        $chart->legend = ['in' => 'In', 'out' => 'Out', 'closing' => 'Closing'];
        $chart->periods = $this->periods([[1, 2, 3]]);
        $chart->validate();
        self::assertNull($chart->minimumY());

        $chart->minimum = 2;
        $this->expectExceptionMessageIsOrContains('The legend of a balance chart with a minimum');
        $chart->validate();
    }

    public function testRefusesAPeriodWithoutWholeMoneyIn(): void
    {
        $chart = new BalanceChart(new LocaleSwitcher('en', []));
        $chart->label = 'Chart';
        $chart->legend = self::LEGEND;
        $chart->periods = [['label' => 'P', 'in' => '1', 'out' => 2, 'closing' => 3, 'inText' => 'a', 'outText' => 'b', 'closingText' => 'c']];

        $this->expectExceptionMessageIsOrContains('A period of a balance chart needs the whole number "in".');
        $chart->validate();
    }

    public function testABelowPeriodIsMarked(): void
    {
        $chart = new BalanceChart(new LocaleSwitcher('en', []));
        $chart->label = 'Chart';
        $chart->legend = self::LEGEND;
        $chart->periods = [['label' => 'P', 'in' => 1, 'out' => 2, 'closing' => 3, 'inText' => 'a', 'outText' => 'b', 'closingText' => 'c', 'below' => true]];
        $chart->validate();

        self::assertTrue($chart->columns()[0]['below']);
    }
}
