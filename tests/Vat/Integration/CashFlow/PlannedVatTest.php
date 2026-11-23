<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Integration\CashFlow;

use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\ForecastReads;
use Foresi\CashFlow\Application\Forecasts;
use Foresi\CashFlow\Application\PlannedItemVatRule;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\CashFlow\Contract\ActualFlow;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\ForecastQuery;
use Foresi\CashFlow\Contract\ForecastRead;
use Foresi\CashFlow\Contract\ForecastReader;
use Foresi\CashFlow\Contract\ReadFlow;
use Foresi\CashFlow\Infrastructure\Source\PlannedItemFlows;
use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Contract\FirmVatStatuses;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Application\AddStatusInput;
use Foresi\TaxesAndContributions\Application\ProfileWrites;
use Foresi\Tests\CashFlow\Support\FakeActualSource;
use Foresi\Tests\CashFlow\Support\FakeSource;
use Foresi\Tests\Vat\Support\VatTestCase;
use Foresi\Vat\Application\CalculationStep;
use Foresi\Vat\Application\PeriodAmounts;
use Foresi\Vat\Application\VatAccess;
use Foresi\Vat\Application\VatPeriods;
use Foresi\Vat\Application\VatRules;
use Foresi\Vat\Application\VatStore;
use Foresi\Vat\Contract\PlannedVatAmount;
use Foresi\Vat\Contract\VatPlanSource;
use Foresi\Vat\Infrastructure\Api\VatJson;
use Foresi\Vat\Integration\CashFlow\PlannedVat;
use Foresi\Vat\Integration\CashFlow\VatFlows;
use Psr\Log\NullLogger;

/**
 * The VAT of the planned items: money planned in gives output VAT and money
 * planned out input VAT, in the period of its day, read through the
 * forecast without the VAT's own flows. Martin is a monthly payer from
 * 12 November 2026; today is 20 November 2026.
 */
final class PlannedVatTest extends VatTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->stretch(VatStatus::NotRegistered, null, null);
        $this->stretch(VatStatus::Registered, VatPeriodLength::Month, '2026-11-12');
    }

    public function testAPlannedIncomeGivesOutputInItsPeriod(): void
    {
        $writes = self::getContainer()->get(PlannedItemWrites::class);
        $consulting = $writes->create(new CreatePlannedItemInput('in', 'Consulting', 'revenue', '12100.00', '2026-12-10', vatMode: 'included', vatRate: '21'));
        $writes->create(new CreatePlannedItemInput('out', 'Office rent', 'rent', '10000.00', '2027-01-15', vatMode: 'excluded', vatRate: '21'));
        $writes->create(new CreatePlannedItemInput('out', 'Software', 'software', '1000.00', '2026-12-10'));
        $writes->create(new CreatePlannedItemInput('out', 'Tax advance', 'taxes', '1000.00', '2026-12-15', vatMode: 'included', vatRate: '21'));
        $this->em->flush();

        $list = $this->periods()->list('2026-11', '2027-01');

        $amounts = array_map(static fn (\Foresi\Vat\Application\PeriodView $view): PeriodAmounts => $view->amounts, $list->periods);
        self::assertSame([[0, 0], [210_000, 0], [0, 210_000]], array_map(static fn (PeriodAmounts $amounts): array => [$amounts->plansPart->output->minor(), $amounts->plansPart->input->minor()], $amounts), 'The consulting gives 2,100 of output in December, the rent 2,100 of input in January; the software states no VAT and taxes carry none.');
        self::assertSame([0, 210_000, -210_000], array_map(static fn (PeriodAmounts $amounts): int => $amounts->result->minor(), $amounts), 'The plans count in the result.');
        self::assertSame([
            [CalculationStep::OUTPUT, 210_000, [PlannedVat::KEY]],
            [CalculationStep::INPUT, 0, [PlannedVat::KEY]],
            [CalculationStep::RESULT, 210_000, [CalculationStep::OUTPUT, CalculationStep::INPUT]],
        ], array_map(static fn (CalculationStep $step): array => [$step->name, $step->amount->minor(), $step->sources], $amounts[1]->steps));
        self::assertNotContains('plans_not_counted', $list->knownGaps);
        self::assertNotContains(PlannedVat::KEY, $list->unavailableSources);

        $detail = VatJson::detail($this->periods()->get('2026-12'));
        self::assertSame([[
            'source' => PlannedVat::KEY,
            'subject' => ['type' => 'planned_item', 'id' => $consulting->id(), 'url' => null],
            'label' => 'Consulting',
            'on' => '2026-12-10',
            'output' => ['amount_minor' => 210_000, 'currency' => 'CZK'],
            'input' => ['amount_minor' => 0, 'currency' => 'CZK'],
        ]], $detail['plans']);
    }

    public function testAPlanBeforeThePayerDayOrTodayCountsNothing(): void
    {
        $this->stretch(VatStatus::NotRegistered, null, '2026-12-01');
        $this->stretch(VatStatus::Registered, VatPeriodLength::Month, '2026-12-15');
        $writes = self::getContainer()->get(PlannedItemWrites::class);
        $writes->create(new CreatePlannedItemInput('in', 'Consulting', 'revenue', '12100.00', '2026-11-19', repeat: 'week', vatMode: 'included', vatRate: '21'));
        $this->em->flush();

        $plans = array_map(static fn (\Foresi\Vat\Application\PeriodView $view): array => array_map(static fn (PlannedVatAmount $plan): string => $plan->on->format('Y-m-d'), $view->amounts->plans), $this->periods()->list('2026-11', '2026-12')->periods);

        self::assertSame([['2026-11-26'], ['2026-12-17', '2026-12-24', '2026-12-31']], $plans, 'From today only, and not on the days the firm is not a payer.');
    }

    public function testAFulfilledPlannedOccurrenceIsNotCounted(): void
    {
        self::getContainer()->get(PlannedItemWrites::class)->create(new CreatePlannedItemInput('in', 'Retainer', 'revenue', '12100.00', '2026-11-25', repeat: 'month', matchVariableSymbol: '2026001', vatMode: 'included', vatRate: '21'));
        $this->em->flush();
        $bank = new FakeActualSource('test.bank', [new ActualFlow('test.bank', new FlowSubject('bank_movement', 'm-1'), FlowDirection::In, Money::of('12100.00', Currency::CZK), new \DateTimeImmutable('2026-11-18', new \DateTimeZone('Europe/Prague')), variableSymbol: '2026001')]);
        $container = self::getContainer();
        $reads = new ForecastReads($container->get(CashFlowAccess::class), $container->get(CashFlowStore::class), $container->get(InstanceClock::class), $container->get(ExchangeRates::class), sources: [new PlannedItemFlows($container->get(CashFlowStore::class), $container->get(PlannedItemVatRule::class))], actualSources: [$bank]);

        $plans = iterator_to_array(new PlannedVat($reads, $container->get(InstanceClock::class), $container->get(ApiTokenContext::class))->between($this->day('2026-11-01'), $this->day('2026-12-31')), false);

        self::assertSame([['2026-12-25', 210_000]], array_map(static fn (PlannedVatAmount $plan): array => [$plan->on->format('Y-m-d'), $plan->output->minor()], $plans), 'The November occurrence was paid already, so its VAT is in the documents or nowhere, never twice.');
    }

    public function testThePlansAreReadWithoutTheVatFlowsAndTheCategoriesWithoutVat(): void
    {
        $reader = new class implements ForecastReader {
            /** @var list<ForecastQuery> */
            public array $queries = [];

            public function read(ForecastQuery $query): ForecastRead
            {
                $this->queries[] = $query;

                return new ForecastRead([
                    new ReadFlow('cash-flow.planned_items', new FlowSubject('planned_item', 'p-1'), FlowDirection::Out, Money::ofMinor(12_100, Currency::EUR), Money::ofMinor(296_450, Currency::CZK), $query->from, FlowCategory::Software, FlowCertainty::Plan, [], 'Hosting', Money::ofMinor(2_100, Currency::EUR)),
                    new ReadFlow('cash-flow.planned_items', new FlowSubject('planned_item', 'p-2'), FlowDirection::Out, Money::ofMinor(10_000, Currency::CZK), Money::ofMinor(10_000, Currency::CZK), $query->from, FlowCategory::Rent, FlowCertainty::Plan, [], 'Rent'),
                ], [], [], false);
            }
        };
        $container = self::getContainer();
        $source = new PlannedVat($reader, $container->get(InstanceClock::class), $container->get(ApiTokenContext::class));

        $plans = iterator_to_array($source->between($this->day('2026-11-01'), $this->day('2030-12-31')), false);

        $query = $reader->queries[0];
        self::assertSame([[VatFlows::KEY], PlannedVat::EXCLUDED_CATEGORIES, [FlowCertainty::Plan], '2026-11-20', '2028-11-20'], [$query->excludeSources, $query->excludeCategories, $query->certainties, $query->from->format('Y-m-d'), $query->to->format('Y-m-d')], 'From today to 24 months ahead at most.');
        self::assertSame([[0, 51_450, 'Hosting']], array_map(static fn (PlannedVatAmount $plan): array => [$plan->output->minor(), $plan->input->minor(), $plan->label], $plans), 'The VAT in CZK in the ratio of the amount; a flow without VAT counts nothing.');
        self::assertSame([], iterator_to_array($source->between($this->day('2026-10-01'), $this->day('2026-11-19')), false), 'Nothing before today.');
        self::assertCount(1, $reader->queries, 'A range before today reads nothing.');
    }

    public function testFailingPlannedItemsLeaveThePlansUnavailable(): void
    {
        $container = self::getContainer();
        $broken = new FakeSource('cash-flow.planned_items', [], fails: true);
        $reads = new ForecastReads($container->get(CashFlowAccess::class), $container->get(CashFlowStore::class), $container->get(InstanceClock::class), $container->get(ExchangeRates::class), sources: [$broken]);
        $periods = $this->vat([new PlannedVat($reads, $container->get(InstanceClock::class), $container->get(ApiTokenContext::class))]);

        self::assertSame([PlannedVat::KEY], array_values(array_diff($periods->list('2026-11', '2026-12')->unavailableSources, VatPeriods::EXPECTED_SOURCES, VatPeriods::EXPECTED_SCHEDULE_SOURCES)));
    }

    public function testTheVatSourceInsideAForecastDoesNotRecurse(): void
    {
        // The owner with an activity: the tax estimate reads the forecast from inside it too.
        $this->signIn($this->owner);
        self::getContainer()->get(ProfileWrites::class)->addStatus(new AddStatusInput('activity', 'main', '2026-01-01'));
        $writes = self::getContainer()->get(PlannedItemWrites::class);
        $writes->create(new CreatePlannedItemInput('in', 'Consulting', 'revenue', '12100.00', '2026-12-10', vatMode: 'included', vatRate: '21'));
        $this->em->flush();
        $counted = new class(self::getContainer()->get(PlannedVat::class)) implements VatPlanSource {
            public int $reads = 0;

            public function __construct(private readonly PlannedVat $plans)
            {
            }

            public function key(): string
            {
                return $this->plans->key();
            }

            public function mayReadDetails(): bool
            {
                return $this->plans->mayReadDetails();
            }

            public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
            {
                ++$this->reads;

                return $this->plans->between($from, $to);
            }
        };
        self::getContainer()->set(VatPeriods::class, $this->vat([$counted]));

        $forecast = self::getContainer()->get(Forecasts::class)->of(months: 3);

        self::assertSame(1, $counted->reads, 'vat.periods runs exactly once, and the plans it reads do not ask it again.');
        $vat = array_values(array_filter($this->flowsOf($forecast), static fn (array $flow): bool => VatFlows::KEY === $flow[0]));
        self::assertSame([[VatFlows::KEY, '2027-01-25', 210_000]], $vat, 'The VAT of the planned consulting is due with the December return.');
    }

    /**
     * The VAT periods with the plan sources given and the container's others.
     *
     * @param list<VatPlanSource> $plans
     */
    private function vat(array $plans): VatPeriods
    {
        $container = self::getContainer();

        return new VatPeriods($container->get(VatAccess::class), $container->get(VatRules::class), $container->get(FirmVatStatuses::class), $container->get(InstanceClock::class), [], new NullLogger(), $container->get(ExchangeRates::class), store: $container->get(VatStore::class), plans: $plans);
    }

    /**
     * @return list<array{string, string, int}> the source, the day and the CZK amount of every flow
     */
    private function flowsOf(\Foresi\CashFlow\Application\Forecast $forecast): array
    {
        $flows = [];
        foreach ($forecast->periods as $period) {
            foreach ($period->lines as $line) {
                $flows[] = [$line->flow->source, $line->countedOn->format('Y-m-d'), $line->amount->minor()];
            }
        }

        return $flows;
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('Europe/Prague'));
    }
}
