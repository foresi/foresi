<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Application;

use Foresi\CashFlow\Application\BalanceWrites;
use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\CollectedFlows;
use Foresi\CashFlow\Application\CzkAmounts;
use Foresi\CashFlow\Application\Forecast;
use Foresi\CashFlow\Application\ForecastFlows;
use Foresi\CashFlow\Application\Forecasts;
use Foresi\CashFlow\Application\HiddenTotal;
use Foresi\CashFlow\Application\RecordCheckpointInput;
use Foresi\CashFlow\Application\UndatedLine;
use Foresi\CashFlow\Contract\CashFlowSource;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\UndatedFlow;
use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;
use Foresi\Tests\CashFlow\Support\FakeSource;
use Foresi\Tests\CashFlow\Support\FakeUndatedSource;
use Psr\Log\NullLogger;

/**
 * The amounts without a day: asked of a source only when the forecast wants
 * them, the largest first, the filters of the dated flows applied to them,
 * and in the forecast summed by category and certainty, those of a source the
 * caller may not read summed per source, direction and category.
 */
final class UndatedFlowsTest extends CashFlowTestCase
{
    public function testTheUndatedFlowsAreAskedOnlyWhenWanted(): void
    {
        $source = new FakeUndatedSource('test.deals', [$this->undated('1', 'Deal 1', '100.00')]);

        $collected = $this->collect([$source]);

        self::assertSame(0, $source->undatedAsked);
        self::assertSame([], $collected->undated);
        self::assertSame(['1'], $this->ids($this->collect([$source], undated: true)->undated));
        self::assertSame(1, $source->undatedAsked);
    }

    public function testTheLargestComeFirstThenTheLabelsInTheirOrder(): void
    {
        $source = new FakeUndatedSource('test.deals', [
            $this->undated('d', 'Delta', '50.00'),
            $this->undated('b', 'Beta', '100.00'),
            $this->undated('g', 'Gamma', '500.00'),
            $this->undated('a', 'Alpha', '100.00'),
        ]);

        $lines = $this->collect([$source], undated: true)->undated;

        self::assertSame(['g', 'a', 'b', 'd'], $this->ids($lines));
        self::assertSame([50000, 10000, 10000, 5000], array_map(static fn (UndatedLine $line): int => $line->amount->minor(), $lines));
    }

    public function testCategoriesAndCertaintiesLeaveOutUndatedFlowsAsTheyDoDatedOnes(): void
    {
        $source = new FakeUndatedSource('test.deals', [
            $this->undated('revenue-deal', 'Deal', '100.00'),
            $this->undated('revenue-plan', 'Plan', '100.00', certainty: FlowCertainty::Plan),
            $this->undated('rent-deal', 'Rent', '100.00', FlowDirection::Out, FlowCategory::Rent),
        ]);

        $ids = fn (array $excludeCategories = [], ?array $certainties = null): array => $this->kept($source, $excludeCategories, $certainties);

        self::assertSame(['rent-deal', 'revenue-deal', 'revenue-plan'], $this->sorted($ids()));
        self::assertSame(['rent-deal'], $ids([FlowCategory::Revenue]), 'A category left out, whatever the certainties.');
        self::assertSame(['revenue-plan'], $ids([], [FlowCertainty::Plan]));
        self::assertSame(['rent-deal', 'revenue-deal'], $this->sorted($ids([], [FlowCertainty::Deal])));
        self::assertSame(['revenue-deal'], $ids([FlowCategory::Rent], [FlowCertainty::Deal]));
    }

    public function testATextMatchesTheLabelThenTheSubjectsLabelWhateverTheCaseOfItsLetters(): void
    {
        $source = new FakeUndatedSource('test.deals', [
            $this->undated('1', 'Deal 1', '100.00', subject: 'Šťastný s.r.o.'),
            $this->undated('2', 'Deal 2', '90.00'),
            $this->undated('3', 'ŽÁDOST', '80.00'),
        ]);

        $ids = fn (string $text): array => $this->ids($this->collect([$source], text: $text, undated: true)->undated);

        self::assertSame(['1', '2', '3'], $ids(''));
        self::assertSame(['1'], $ids('ŠŤASTNÝ'), 'The subject\'s label, its capitals lowered as a person reads them.');
        self::assertSame(['1'], $ids('deal 1 šťastný'), 'The label, a space, then the subject\'s label.');
        self::assertSame(['2'], $ids(' DEAL 2 '), 'A flow without a subject\'s label is found by its own.');
        self::assertSame(['3'], $ids('žádost'), 'The label\'s capitals are lowered too.');
        self::assertSame([], $ids('%'));
    }

    public function testAnUnavailableSourceIsListedOnceInAListOfAReadToo(): void
    {
        $broken = static fn (string $key): FakeSource => new FakeSource($key, fails: true);

        $collected = $this->collect([$broken('test.broken'), $broken('test.broken'), $broken('test.other')]);

        self::assertSame(['test.broken', 'test.other'], $collected->unavailable);
    }

    public function testTheForecastSumsTheUndatedFlowsAndThoseItMayNotReadPerSourceDirectionAndCategory(): void
    {
        $this->checkpoint();
        $visible = new FakeUndatedSource('test.deals', [
            $this->undated('v1', 'Deal 1', '100.00'),
            $this->undated('v2', 'Deal 2', '50.00', certainty: FlowCertainty::Plan),
            $this->undated('v3', 'Deal 3', '20.00'),
            $this->undated('v4', 'Rent', '30.00', FlowDirection::Out, FlowCategory::Rent),
            $this->undated('v5', 'Software', '10.00', FlowDirection::Out, FlowCategory::Software, FlowCertainty::Plan),
            $this->undated('v6', 'More rent', '5.00', FlowDirection::Out, FlowCategory::Rent),
        ]);
        $zeta = new FakeUndatedSource('test.zeta', [
            $this->undated('z1', 'Hidden 1', '200.00', source: 'test.zeta'),
            $this->undated('z2', 'Hidden 2', '300.00', source: 'test.zeta'),
            $this->undated('z3', 'Hidden 3', '40.00', FlowDirection::Out, FlowCategory::Rent, source: 'test.zeta'),
            $this->undated('z4', 'Refund', '60.00', FlowDirection::Out, FlowCategory::Revenue, source: 'test.zeta'),
            $this->undated('z5', 'Hidden 5', '8.00', category: FlowCategory::Other, source: 'test.zeta'),
        ], details: false);
        $alpha = new FakeUndatedSource('test.alpha', [
            $this->undated('a1', 'Hidden 4', '7.00', source: 'test.alpha'),
        ], details: false);

        $undated = $this->forecast([$visible, $zeta, $alpha])->undated;

        self::assertSame(['revenue' => 10000 + 5000 + 2000 + 20000 + 30000 + 700, 'other' => 800], $this->minors($undated->inflows));
        self::assertSame(['revenue' => 6000, 'rent' => 3000 + 500 + 4000, 'software' => 1000], $this->minors($undated->outflows));
        self::assertSame(['deal' => 10000 + 2000 + 20000 + 30000 + 700 + 800, 'plan' => 5000], $this->minors($undated->inflowsByCertainty));
        self::assertSame(['deal' => 6000 + 3000 + 500 + 4000, 'plan' => 1000], $this->minors($undated->outflowsByCertainty));
        self::assertSame(['v1', 'v2', 'v4', 'v3', 'v5', 'v6'], $this->ids($undated->lines));
        self::assertSame([
            ['test.alpha', 'in', 'revenue', 700, 1],
            ['test.zeta', 'in', 'other', 800, 1],
            ['test.zeta', 'in', 'revenue', 50000, 2],
            ['test.zeta', 'out', 'rent', 4000, 1],
            ['test.zeta', 'out', 'revenue', 6000, 1],
        ], array_map(static fn (HiddenTotal $hidden): array => [$hidden->source, $hidden->direction->value, $hidden->category->value, $hidden->amount->minor(), $hidden->count], $undated->hidden), 'Per source, direction and category, in that order.');
        self::assertSame(12, $undated->count());
    }

    /**
     * @param list<CashFlowSource>     $sources
     * @param list<FlowCategory>       $excludeCategories
     * @param list<FlowCertainty>|null $certainties
     */
    private function collect(array $sources, array $excludeCategories = [], ?array $certainties = null, string $text = '', bool $undated = false): CollectedFlows
    {
        $container = self::getContainer();
        $today = $container->get(InstanceClock::class)->today();
        $flows = new ForecastFlows($container->get(CashFlowStore::class), new NullLogger(), $sources);
        $czk = new CzkAmounts($container->get(ExchangeRates::class), new NullLogger(), $today);
        $end = new \DateTimeImmutable('2027-01-31', $today->getTimezone());
        if ($undated) {
            return $flows->collect($today, $today, $end, $czk, excludeCategories: $excludeCategories, certainties: $certainties, text: $text, undated: true);
        }

        return $flows->collect($today, $today, $end, $czk, excludeCategories: $excludeCategories, certainties: $certainties, text: $text);
    }

    /**
     * The ids of the undated flows the filters keep.
     *
     * @param array<mixed>      $excludeCategories
     * @param array<mixed>|null $certainties
     *
     * @return list<string>
     */
    private function kept(CashFlowSource $source, array $excludeCategories, ?array $certainties): array
    {
        $categories = array_values(array_filter($excludeCategories, static fn (mixed $category): bool => $category instanceof FlowCategory));
        $kept = null === $certainties ? null : array_values(array_filter($certainties, static fn (mixed $certainty): bool => $certainty instanceof FlowCertainty));

        return $this->ids($this->collect([$source], $categories, $kept, undated: true)->undated);
    }

    /**
     * @param list<CashFlowSource> $sources
     */
    private function forecast(array $sources): Forecast
    {
        $container = self::getContainer();
        $forecasts = new Forecasts($container->get(CashFlowAccess::class), $container->get(CashFlowStore::class), $container->get(InstanceClock::class), $container->get(ExchangeRates::class), new NullLogger(), $sources, []);

        return $forecasts->of(months: 1);
    }

    private function undated(string $id, string $label, string $amount, FlowDirection $direction = FlowDirection::In, FlowCategory $category = FlowCategory::Revenue, FlowCertainty $certainty = FlowCertainty::Deal, ?string $subject = null, string $source = 'test.deals'): UndatedFlow
    {
        return new UndatedFlow($source, new FlowSubject('deal', $id, null, $subject), $direction, Money::of($amount, Currency::of('CZK')), $category, $certainty, $label);
    }

    private function checkpoint(): void
    {
        self::getContainer()->get(BalanceWrites::class)->checkpoint(new RecordCheckpointInput($this->account->id(), '2026-11-16', '1000.00'));
    }

    /**
     * @param list<UndatedLine> $lines
     *
     * @return list<string>
     */
    private function ids(array $lines): array
    {
        return array_map(static fn (UndatedLine $line): string => $line->flow->subject->id, $lines);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, Money> $amounts
     *
     * @return array<string, int>
     */
    private function minors(array $amounts): array
    {
        return array_map(static fn (Money $amount): int => $amount->minor(), $amounts);
    }
}
