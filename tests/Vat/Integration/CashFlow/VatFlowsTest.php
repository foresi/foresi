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
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\CashFlow\Contract\CashFlowSource;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowFlag;
use Foresi\CashFlow\Contract\ForecastQuery;
use Foresi\CashFlow\Contract\ForecastReader;
use Foresi\CashFlow\Contract\ReadFlow;
use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Vat\Support\VatTestCase;
use Foresi\Vat\Application\PeriodWrites;
use Foresi\Vat\Application\ReopenPeriodInput;
use Foresi\Vat\Application\SetReturnAmountInput;
use Foresi\Vat\Application\SettlePeriodInput;
use Foresi\Vat\Application\VatAccess;
use Foresi\Vat\Application\VatPeriods;
use Foresi\Vat\Contract\PossibleDuplicate;
use Foresi\Vat\Domain\PeriodEntry;
use Foresi\Vat\Domain\VatSettings;
use Foresi\Vat\Integration\CashFlow\DuplicateVatItems;
use Foresi\Vat\Integration\CashFlow\VatFlows;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The VAT of the periods in the cash flow: one estimate flow in the
 * category vat for each period not settled that expects money, awaited and
 * never overdue once its day has passed, and gone once a payment or a
 * person settles the period. Today is 5 January 2027 unless stated.
 */
final class VatFlowsTest extends VatTestCase
{
    use BuildsMartinsVat;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2027-01-05 10:00:00', 'UTC'));
        $this->stretch(VatStatus::NotRegistered, null, null);
        $this->stretch(VatStatus::Registered, VatPeriodLength::Month, '2026-11-12');
        $this->em->persist(new VatSettings(self::OFFICE, self::SYMBOL));
        $this->em->flush();
    }

    public function testAnUnpaidPastPeriodIsNotYetPaidAndAwaitedNeverOverdue(): void
    {
        $flows = $this->flows();

        self::assertSame(['2026-11', '2026-12'], array_keys($flows), 'January expects nothing, so it has no flow.');
        $november = $flows['2026-11'];
        self::assertSame([VatFlows::KEY, 'vat_period', '2026-11', '/api/v1/vat/periods/2026-11'], [$november->source, $november->subject->type, $november->subject->id, $november->subject->url]);
        self::assertSame([FlowDirection::Out, 189_000, '2026-12-28', 'Europe/Prague'], [$november->direction, $november->amount->minor(), $november->expectedOn->format('Y-m-d'), $november->expectedOn->getTimezone()->getName()]);
        self::assertSame([FlowCategory::Vat, FlowCertainty::Estimate, [FlowFlag::Estimated, FlowFlag::Awaited]], [$november->category, $november->certainty, $november->flags]);
        self::assertSame('DPH za listopad 2026', $november->label);
        $december = $flows['2026-12'];
        self::assertSame([FlowDirection::In, 300_000, '2027-02-24', [FlowFlag::Estimated]], [$december->direction, $december->amount->minor(), $december->expectedOn->format('Y-m-d'), $december->flags]);
        self::assertStringStartsWith('Nadměrný odpočet DPH za prosinec 2026', $december->label);
        self::assertStringContainsString('§ 154', $december->label);

        $read = $this->forecast([$this->source()])->read(new ForecastQuery($this->day('2027-01-05'), $this->day('2027-03-31')));
        $counted = $this->read($read->flows)['2026-11'];
        self::assertSame(['2027-01-05', [FlowFlag::Estimated, FlowFlag::Awaited]], [$counted->on->format('Y-m-d'), $counted->flags], 'Counted today, awaited and not overdue.');
        self::assertStringNotContainsString('overdue', json_encode(array_map(static fn (ReadFlow $flow): array => array_map(static fn (FlowFlag $flag): string => $flag->value, $flow->flags), $read->flows), \JSON_THROW_ON_ERROR));
    }

    public function testASmallerPaymentSettlesThePeriodAndItsFlowLeaves(): void
    {
        $this->paid('m1', '2026-12-20', 1_500);

        self::assertSame(['2026-12'], array_keys($this->flows()));
    }

    public function testAReturnAmountReplacesTheEstimateUntilPaid(): void
    {
        $entry = new PeriodEntry('2026-11');
        $entry->enterReturnAmount($this->czk(2_000));
        $this->em->persist($entry);
        $this->em->flush();

        self::assertSame(200_000, $this->flows()['2026-11']->amount->minor());
        self::assertSame(189_000, $this->vat()->get('2026-11')->period->result->minor(), 'The estimate stays in the answer.');
    }

    public function testAManualSettleRemovesTheFlowAndReopenBringsItBack(): void
    {
        $writes = self::getContainer()->get(PeriodWrites::class);

        $writes->settle(new SettlePeriodInput('2026-11', 'Paid from another account.'));
        self::assertSame(['2026-12'], array_keys($this->flows()));

        $writes->reopen(new ReopenPeriodInput('2026-11'));
        self::assertSame(['2026-11', '2026-12'], array_keys($this->flows()));
    }

    public function testNothingAfterTheLastDayAsked(): void
    {
        self::assertSame(['2026-11'], array_keys($this->flows(to: '2027-02-23')));
        self::assertSame(['2026-11', '2026-12'], array_keys($this->flows(to: '2027-02-24')));
    }

    public function testAFlowIsAwaitedFromTheDayAfterItsDateInPrague(): void
    {
        Clock::set(new MockClock('2026-12-28 22:30:00', 'UTC'));
        $before = $this->flows(today: '2026-12-28')['2026-11'];
        self::assertSame(['2026-12-28', [FlowFlag::Estimated]], [$before->expectedOn->format('Y-m-d'), $before->flags], '23:30 in Prague on the due date.');

        Clock::set(new MockClock('2026-12-28 23:30:00', 'UTC'));
        $after = $this->flows(today: '2026-12-29')['2026-11'];
        self::assertSame(['2026-12-28', [FlowFlag::Estimated, FlowFlag::Awaited]], [$after->expectedOn->format('Y-m-d'), $after->flags], '00:30 in Prague on 29 December, though UTC is still the 28th.');

        $read = $this->forecast([$this->source()])->read(new ForecastQuery($this->day('2026-12-29'), $this->day('2027-01-31')));
        self::assertSame(['2026-12-29', [FlowFlag::Estimated, FlowFlag::Awaited]], [$this->read($read->flows)['2026-11']->on->format('Y-m-d'), $this->read($read->flows)['2026-11']->flags]);
    }

    public function testAQuarterIsNamedByItsQuarter(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM party_vat_status_period');
        $this->stretch(VatStatus::Registered, VatPeriodLength::Quarter, '2026-10-01');

        // November's 1,890 to pay and December's 3,000 back give 1,110 back for the quarter.
        self::assertStringStartsWith('Nadměrný odpočet DPH za 4. čtvrtletí 2026 (', $this->flows()['2026-Q4']->label);
        self::assertSame(111_000, $this->flows()['2026-Q4']->amount->minor());
    }

    public function testAMemberWithVatReadSeesDetailsAndATokenWithoutItSeesCategoryTotals(): void
    {
        self::assertTrue($this->source()->mayReadDetails());
        self::assertTrue($this->source($this->token('vat:read'))->mayReadDetails());
        self::assertTrue($this->source($this->token('vat:write'))->mayReadDetails());
        self::assertFalse($this->source($this->token('cashflow:read'))->mayReadDetails());

        $read = $this->forecast([$this->source($this->token('cashflow:read'))])->read(new ForecastQuery($this->day('2027-01-05'), $this->day('2027-03-31')));
        self::assertSame([VatFlows::KEY], $read->restrictedSources);
        $totals = array_values(array_filter($read->flows, static fn (ReadFlow $flow): bool => VatFlows::KEY === $flow->source));
        self::assertCount(2, $totals);
        self::assertSame([null, null], [$totals[0]->subject, $totals[0]->label]);

        $this->signIn($this->guest);
        self::assertFalse($this->source()->mayReadDetails());
    }

    public function testTheInstalledSourceIsInTheForecast(): void
    {
        self::getContainer()->get(PeriodWrites::class)->setReturnAmount(new SetReturnAmountInput('2026-11', '2000'));

        $read = self::getContainer()->get(ForecastReader::class)->read(new ForecastQuery($this->day('2027-01-05'), $this->day('2027-03-31')));

        $vat = $this->read($read->flows);
        self::assertSame(['2026-11'], array_keys($vat), 'The installed periods count no documents here, only the return amount.');
        self::assertSame([200_000, FlowCategory::Vat], [$vat['2026-11']->amountCzk->minor(), $vat['2026-11']->category]);
        self::assertSame([], $read->unavailableSources);
    }

    public function testAnActiveVatPlannedItemIsListedAsAPossibleDuplicate(): void
    {
        $writes = self::getContainer()->get(PlannedItemWrites::class);
        $vat = $writes->create(new CreatePlannedItemInput('out', 'VAT', 'vat', '2000.00', '2027-01-25', repeat: 'month'));
        $back = $writes->create(new CreatePlannedItemInput('in', 'VAT back', 'vat', '100.00', '2027-02-10'));
        $writes->create(new CreatePlannedItemInput('out', 'Office rent', 'rent', '10000.00', '2027-01-10', repeat: 'month'));
        $this->em->flush();

        $items = self::getContainer()->get(DuplicateVatItems::class)->activeVatItems();

        self::assertSame([[$vat->id(), 'VAT', '/cash-flow/planned-costs/'.$vat->id()], [$back->id(), 'VAT back', '/cash-flow/planned-income/'.$back->id()]], array_map(static fn (PossibleDuplicate $item): array => [$item->id, $item->name, $item->url], $items));
        self::assertCount(2, self::getContainer()->get(VatPeriods::class)->list('2026-11', '2026-11')->duplicates);
        $this->em->clear();
        self::assertTrue(self::getContainer()->get(CashFlowStore::class)->plannedItem($vat->id())?->isIncluded(), 'Nothing is ended or left out.');
    }

    public function testThePlannedItemsAreReadWithoutTheVatFlows(): void
    {
        $reader = new class implements ForecastReader {
            /** @var list<ForecastQuery> */
            public array $queries = [];

            public function read(ForecastQuery $query): \Foresi\CashFlow\Contract\ForecastRead
            {
                $this->queries[] = $query;

                return new \Foresi\CashFlow\Contract\ForecastRead([], [], [], false);
            }
        };
        $container = self::getContainer();

        new DuplicateVatItems($reader, $container->get(InstanceClock::class), $container->get(UrlGeneratorInterface::class))->activeVatItems();

        $query = $reader->queries[0];
        self::assertSame([[VatFlows::KEY], [FlowCertainty::Plan], '2027-01-05', '2028-01-05'], [$query->excludeSources, $query->certainties, $query->from->format('Y-m-d'), $query->to->format('Y-m-d')]);
        self::assertNotContains(FlowCategory::Vat, $query->excludeCategories);
        self::assertCount(\count(FlowCategory::cases()) - 1, $query->excludeCategories);
    }

    /**
     * The flows of the source to the day, by period.
     *
     * @return array<string, ExpectedFlow>
     */
    private function flows(string $to = '2027-12-31', string $today = '2027-01-05'): array
    {
        $flows = [];
        foreach ($this->source()->flows($this->day($today), $this->day($to), $this->day($today)) as $flow) {
            $flows[$flow->subject->id] = $flow;
        }

        return $flows;
    }

    private function source(?ApiTokenContext $tokens = null): VatFlows
    {
        $container = self::getContainer();

        return new VatFlows($this->vat(), $container->get(VatAccess::class), $tokens ?? $container->get(ApiTokenContext::class), $container->get(TranslatorInterface::class), $container->get(UrlGeneratorInterface::class));
    }

    /**
     * @param list<CashFlowSource> $sources
     */
    private function forecast(array $sources): ForecastReads
    {
        $container = self::getContainer();

        return new ForecastReads($container->get(CashFlowAccess::class), $container->get(CashFlowStore::class), $container->get(InstanceClock::class), $container->get(ExchangeRates::class), sources: $sources);
    }

    /**
     * @param list<ReadFlow> $flows
     *
     * @return array<string, ReadFlow>
     */
    private function read(array $flows): array
    {
        $read = [];
        foreach ($flows as $flow) {
            if (VatFlows::KEY === $flow->source && null !== $flow->subject) {
                $read[$flow->subject->id] = $flow;
            }
        }

        return $read;
    }

    private function token(string $scope): ApiTokenContext
    {
        return new readonly class($scope) implements ApiTokenContext {
            public function __construct(private string $scope)
            {
            }

            public function grant(): ApiTokenGrant
            {
                return new ApiTokenGrant('019a0000-0000-7000-8000-000000000001', '019a0000-0000-7000-8000-0000000000aa', 'Example', [ApiScope::from($this->scope)], new \DateTimeImmutable('2027-12-01'));
            }

            public function requireScope(ApiScope $scope): ApiTokenGrant
            {
                return $this->grant();
            }
        };
    }
}
