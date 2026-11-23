<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Application;

use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowRefused;
use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\ChangePlannedItemInput;
use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\ForecastReads;
use Foresi\CashFlow\Application\PlannedItemVatRule;
use Foresi\CashFlow\Contract\ActualFlow;
use Foresi\CashFlow\Contract\ActualFlowSource;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\ForecastQuery;
use Foresi\CashFlow\Contract\PlannedVatMode;
use Foresi\CashFlow\Contract\ReadFlow;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\CashFlow\Domain\PlannedItemVat;
use Foresi\CashFlow\Infrastructure\Source\PlannedItemFlows;
use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\VatStatus;
use Foresi\Party\Domain\VatStatusPeriod;
use Foresi\Shared\Application\Api\Omitted;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;
use Foresi\Tests\CashFlow\Support\FakeActualSource;

/**
 * The VAT of a planned item: its amount includes the VAT or the VAT comes on
 * top, and it counts only on the days the firm is a VAT payer. Today is
 * 17 November 2026 in Prague.
 */
final class PlannedItemVatTest extends CashFlowTestCase
{
    public function testIncluded12100At21GivesNet10000AndVat2100(): void
    {
        $this->payerFrom('2026-01-01');
        $item = $this->item('included', '21', amount: '12100.00');

        $flow = $this->flows()[0];

        self::assertSame([1_210_000, 1_000_000, 210_000], $this->money($item->vat()));
        self::assertSame([1_210_000, 210_000, 210_000], [$flow->amount->minor(), $flow->vat?->minor(), $flow->vatCzk()?->minor()]);
    }

    public function testExcluded10000At21GivesAFlowOf12100(): void
    {
        $this->payerFrom('2026-01-01');
        $item = $this->item('excluded', '21');

        $flow = $this->flows()[0];

        self::assertSame([1_210_000, 1_000_000, 210_000], $this->money($item->vat()));
        self::assertSame([1_210_000, 210_000], [$flow->amount->minor(), $flow->vat?->minor()]);
    }

    public function testTheVatIsRoundedOnceToTheMinorUnit(): void
    {
        self::assertSame([10_001, 8_890, 1_111], $this->money(PlannedItemVat::of(Money::ofMinor(10_001, Currency::CZK), PlannedVatMode::Included, '12.50')), '10,001 × 12.5 / 112.5 is 1,111.22, which rounds down.');
        self::assertSame([10_006, 8_894, 1_112], $this->money(PlannedItemVat::of(Money::ofMinor(10_006, Currency::CZK), PlannedVatMode::Included, '12.50')), '1,111.78 rounds up.');
        self::assertSame([10_013, 8_900, 1_113], $this->money(PlannedItemVat::of(Money::ofMinor(8_900, Currency::CZK), PlannedVatMode::Excluded, '12.50')), '8,900 × 12.5 % is 1,112.5, which rounds half up.');
        self::assertSame([1_000_000, 1_000_000, 0], $this->money(PlannedItemVat::of(Money::ofMinor(1_000_000, Currency::CZK), PlannedVatMode::Excluded, '0.00')));
        self::assertNull(PlannedItemVat::of(Money::ofMinor(1_000_000, Currency::CZK), PlannedVatMode::None, null));
    }

    public function testAnOccurrenceBeforeThePayerDayHasNoVat(): void
    {
        $this->payerFrom('2026-12-20', notBefore: true);
        $this->item('excluded', '21', on: '2026-11-19', repeat: 'month');

        $flows = $this->flows('2027-01-31');

        self::assertSame([
            ['2026-11-19', 1_000_000, null],
            ['2026-12-19', 1_000_000, null],
            ['2027-01-19', 1_210_000, 210_000],
        ], array_map(static fn (ReadFlow $flow): array => [$flow->on->format('Y-m-d'), $flow->amount->minor(), $flow->vat?->minor()], $flows), 'Before the payer day the amount moves as stated, without VAT.');
    }

    public function testThePayerDayItselfHasVat(): void
    {
        $this->payerFrom('2026-12-19', notBefore: true);
        $this->item('excluded', '21', on: '2026-11-19', repeat: 'month');

        self::assertSame([null, 210_000], array_map(static fn (ReadFlow $flow): ?int => $flow->vat?->minor(), $this->flows('2026-12-31')));
    }

    public function testAFirmThatStopsBeingAPayerCountsNoVatFromThatDay(): void
    {
        $this->payerFrom('2026-01-01');
        $this->em->persist(new VatStatusPeriod(VatStatus::NotRegistered, null, new \DateTimeImmutable('2026-12-01')));
        $this->em->flush();
        $this->item('included', '21', on: '2026-11-20', amount: '12100.00', repeat: 'month');

        self::assertSame([[1_210_000, 210_000], [1_210_000, null]], array_map(static fn (ReadFlow $flow): array => [$flow->amount->minor(), $flow->vat?->minor()], $this->flows('2026-12-31')));
    }

    public function testWithoutAVatModeTheFlowHasNoVatAlsoForAPayer(): void
    {
        $this->payerFrom('2026-01-01');
        $this->planned();

        $flow = $this->flows()[0];

        self::assertSame([1_000_000, null, null], [$flow->amount->minor(), $flow->vat, $flow->vatCzk()]);
    }

    public function testAForeignItemConvertsItsVatAsItsAmount(): void
    {
        $this->payerFrom('2026-01-01');
        $this->writes()->create(new CreatePlannedItemInput('out', 'Software', 'software', '100.00', '2026-11-20', 'EUR', vatMode: 'excluded', vatRate: '21'));

        $flow = $this->flows()[0];

        self::assertSame([12_100, 2_100, 296_450, 51_450], [$flow->amount->minor(), $flow->vat?->minor(), $flow->amountCzk->minor(), $flow->vatCzk()?->minor()], '121 EUR at 24.500 is 2,964.50 CZK, of which 21 EUR is 514.50.');
    }

    public function testAPaymentWithTheVatOnTopFulfilsTheOccurrence(): void
    {
        $this->payerFrom('2026-01-01');
        $this->writes()->create(new CreatePlannedItemInput('out', 'Office rent', 'rent', '10000.00', '2026-11-20', repeat: 'month', matchVariableSymbol: '2026', vatMode: 'excluded', vatRate: '21'));
        $bank = new FakeActualSource('test.bank', [
            new ActualFlow('test.bank', new FlowSubject('bank_movement', 'm-1'), FlowDirection::Out, Money::of('10000.00', Currency::CZK), $this->day('2026-11-18'), variableSymbol: '2026'),
            new ActualFlow('test.bank', new FlowSubject('bank_movement', 'm-2'), FlowDirection::Out, Money::of('12100.00', Currency::CZK), $this->day('2026-12-18'), variableSymbol: '2026'),
        ]);

        $flows = $this->flows('2027-01-31', [$bank]);

        self::assertSame(['2026-11-20', '2027-01-20'], array_map(static fn (ReadFlow $flow): string => $flow->on->format('Y-m-d'), $flows), 'The 12,100 paid in December fulfils its occurrence; the 10,000 of November is not what the occurrence moves.');
    }

    public function testTheRulesOfTheModeAndTheRate(): void
    {
        foreach ([
            ['none', '21', 'vat_rate_without_mode', 'vatRate'],
            ['included', null, 'vat_rate_required', 'vatRate'],
            ['excluded', ' ', 'vat_rate_required', 'vatRate'],
            ['included', '101', 'vat_rate_invalid', 'vatRate'],
            ['included', '100.01', 'vat_rate_invalid', 'vatRate'],
            ['included', '21.123', 'vat_rate_invalid', 'vatRate'],
            ['included', '-1', 'vat_rate_invalid', 'vatRate'],
            ['included', '1000', 'vat_rate_invalid', 'vatRate'],
            ['gross', '21', 'vat_mode_unknown', 'vatMode'],
        ] as [$mode, $rate, $reason, $field]) {
            self::assertSame([$reason, $field], $this->refused($mode, $rate), \sprintf('%s at %s is refused.', $mode, $rate ?? 'null'));
        }

        self::assertSame(['100', '21', '12.5', '0', '7.05'], array_map(fn (string $rate): ?string => $this->item('included', $rate)->vatRate(), ['100.00', '21', '12.5', '0', '007.05']));
        self::assertSame([null, '21'], [$this->item('none', ' ')->vatRate(), $this->item('included', ' 21 ')->vatRate()], 'A blank rate is none, and a rate is trimmed.');
    }

    public function testAChangeKeepsTheRateAndNoneClearsIt(): void
    {
        $item = $this->item('included', '21');

        $this->writes()->change(new ChangePlannedItemInput($item->id(), vatMode: 'excluded'));
        self::assertSame([PlannedVatMode::Excluded, '21'], [$item->vatMode(), $item->vatRate()]);

        $this->writes()->change(new ChangePlannedItemInput($item->id(), vatRate: '12'));
        self::assertSame([PlannedVatMode::Excluded, '12'], [$item->vatMode(), $item->vatRate()]);

        $this->writes()->change(new ChangePlannedItemInput($item->id(), name: 'Rent'));
        self::assertSame([PlannedVatMode::Excluded, '12'], [$item->vatMode(), $item->vatRate()], 'A change of another field keeps the VAT.');

        $this->writes()->change(new ChangePlannedItemInput($item->id(), vatMode: 'none'));
        self::assertSame([PlannedVatMode::None, null, null], [$item->vatMode(), $item->vatRate(), $item->vat()]);

        try {
            $this->writes()->change(new ChangePlannedItemInput($item->id(), vatMode: 'included', vatRate: new Omitted()));
            self::fail('A mode without a rate is refused.');
        } catch (CashFlowRefused $refused) {
            self::assertSame('vat_rate_required', $refused->reason());
        }
    }

    public function testVatMissingIsMarkedWhileAPayer(): void
    {
        $rule = self::getContainer()->get(PlannedItemVatRule::class);
        $rent = $this->planned();
        $stated = $this->item('excluded', '21');
        $tax = $this->planned(category: 'taxes', name: 'Income tax advance');

        $this->em->persist(new VatStatusPeriod(VatStatus::NotRegistered, null, null));
        $this->em->flush();
        $missing = [[$rule->isMissing($rent), $rule->isMissing($stated), $rule->isMissing($tax)]];

        $this->payerFrom('2026-11-18');
        foreach (['2026-11-17 22:30:00', '2026-11-17 23:30:00'] as $now) {
            $this->clock->modify($now);
            $missing[] = [$rule->isMissing($rent), $rule->isMissing($stated), $rule->isMissing($tax)];
        }

        self::assertSame([[false, false, false], [false, false, false], [true, false, false]], $missing, 'Not a payer: nothing is missing. At 23:30 in Prague on 17 November not a payer yet; at 00:30 on 18 November a payer, and the rent states no VAT; taxes carry none.');
    }

    private function payerFrom(string $day, bool $notBefore = false): void
    {
        if ($notBefore) {
            $this->em->persist(new VatStatusPeriod(VatStatus::NotRegistered, null, null));
        }
        $this->em->persist(new VatStatusPeriod(VatStatus::Registered, VatPeriodLength::Month, new \DateTimeImmutable($day)));
        $this->em->flush();
    }

    private function item(string $mode, ?string $rate, string $on = '2026-11-20', string $amount = '10000.00', ?string $repeat = null): PlannedItem
    {
        return $this->writes()->create(new CreatePlannedItemInput('out', 'Office rent', 'rent', $amount, $on, repeat: $repeat, vatMode: $mode, vatRate: $rate));
    }

    /**
     * @return array{string, ?string}|null the reason and the field of the refusal
     */
    private function refused(string $mode, ?string $rate): ?array
    {
        try {
            $this->item($mode, $rate);
        } catch (CashFlowRefused $refused) {
            return [$refused->reason(), $refused->field()];
        }

        return null;
    }

    /**
     * @return array{int, int, int}|null the flow, the net and the VAT
     */
    private function money(?PlannedItemVat $vat): ?array
    {
        return $vat instanceof PlannedItemVat ? [$vat->flow->minor(), $vat->net->minor(), $vat->vat->minor()] : null;
    }

    /**
     * The planned flows of the forecast from today to the day.
     *
     * @param list<ActualFlowSource> $actuals
     *
     * @return list<ReadFlow>
     */
    private function flows(string $to = '2026-11-30', array $actuals = []): array
    {
        $this->em->flush();
        $container = self::getContainer();
        $reads = new ForecastReads(
            $container->get(CashFlowAccess::class),
            $container->get(CashFlowStore::class),
            $container->get(InstanceClock::class),
            $container->get(ExchangeRates::class),
            sources: [new PlannedItemFlows($container->get(CashFlowStore::class), $container->get(PlannedItemVatRule::class))],
            actualSources: $actuals,
        );

        return $reads->read(new ForecastQuery($this->day('2026-11-17'), $this->day($to)))->flows;
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('Europe/Prague'));
    }
}
