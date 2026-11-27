<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Application;

use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Application\Amounts;
use Foresi\TaxesAndContributions\Application\ProfileReads;
use Foresi\TaxesAndContributions\Application\TaxesRefused;
use Foresi\TaxesAndContributions\Contract\ActualAmount;
use Foresi\TaxesAndContributions\Contract\ActualAmountSource;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\AmountLine;
use Foresi\TaxesAndContributions\Contract\AmountSum;
use Foresi\TaxesAndContributions\Contract\AmountsView;
use Foresi\TaxesAndContributions\Contract\AmountTotals;
use Foresi\TaxesAndContributions\Contract\Subject;
use Foresi\TaxesAndContributions\Contract\VatShare;
use Foresi\TaxesAndContributions\Infrastructure\Api\TaxesJson;
use Foresi\TaxesAndContributions\Infrastructure\Mcp\TaxAmountsTool;
use Foresi\Tests\ExchangeRate\Support\FakeRateSource;
use Foresi\Tests\TaxesAndContributions\Support\FakeAmountSource;
use Foresi\Tests\TaxesAndContributions\Support\FirmVat;
use Foresi\Tests\TaxesAndContributions\Support\RecordingLogger;
use Foresi\Tests\TaxesAndContributions\Support\TaxesTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The actual amounts on a cash basis (B2, B3) from sources the test gives:
 * each payment on its own day, in CZK at the rate of that day, with VAT
 * before the owner became a VAT payer and without it after. The day in the
 * time zone of the Czech rules is in Integration/Country.
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

    public function testAPartialPaymentSplitsNetAndVatInTheDocumentRatio(): void
    {
        $share = new VatShare(1_000_000, 1_210_000);

        [$net, $vat] = $share->split(Money::ofMinor(605_000, Currency::CZK));

        self::assertSame([500_000, 105_000], [$net->minor(), $vat->minor()]);
        // 1.00 of 12,100 is 0.8264… net: nothing is lost, and the unit left over goes to the net.
        self::assertSame([83, 17], array_map(static fn (Money $part): int => $part->minor(), $share->split(Money::ofMinor(100, Currency::CZK))));
        self::assertSame([-500_000, -105_000], array_map(static fn (Money $part): int => $part->minor(), $share->split(Money::ofMinor(-605_000, Currency::CZK))), 'A refund splits the same way, below zero.');
        self::assertSame([1, 0], array_map(static fn (Money $part): int => $part->minor(), new VatShare(1, 2)->split(Money::ofMinor(1, Currency::CZK))), 'The unit left over goes to the net.');
        self::assertSame([700, 0], array_map(static fn (Money $part): int => $part->minor(), new VatShare(0, 0)->split(Money::ofMinor(700, Currency::CZK))), 'Without a gross, all of it is net.');
        self::assertSame([0, 300], array_map(static fn (Money $part): int => $part->minor(), new VatShare(0, 1_000)->split(Money::ofMinor(300, Currency::CZK))));
        self::assertSame([500, 0], array_map(static fn (Money $part): int => $part->minor(), new VatShare(-10, 20)->split(Money::ofMinor(500, Currency::CZK))), 'A net against its gross has no ratio.');
        self::assertSame([500, 0], array_map(static fn (Money $part): int => $part->minor(), new VatShare(30, 20)->split(Money::ofMinor(500, Currency::CZK))), 'Nor a VAT below zero.');
        self::assertSame(Currency::EUR, new VatShare(10, 12)->split(Money::ofMinor(12, Currency::EUR))[1]->currency());
    }

    public function testTheSumsAreByMonthKindAndSourceAndTheTotalsByKind(): void
    {
        $amounts = $this->amounts([
            new FakeAmountSource('invoicing.payments', [
                $this->amount('invoicing.payments', 'a', AmountKind::Income, '2026-10-05', 1_210_000, 1_000_000),
                $this->amount('invoicing.payments', 'b', AmountKind::Income, '2026-11-02', 605_000, 500_000),
                $this->amount('invoicing.payments', 'c', AmountKind::Income, '2026-11-03', -121_000, -100_000),
            ]),
            new FakeAmountSource('received-documents.payments', [
                $this->amount('received-documents.payments', 'd', AmountKind::Cost, '2026-11-04', 242_000, 200_000),
            ]),
        ])->year(2026);

        self::assertSame(['2026-01-01', '2026-11-20'], [$amounts->from->format('Y-m-d'), $amounts->to->format('Y-m-d')]);
        self::assertSame([
            ['2026-10', 'income', 'invoicing.payments', 1_210_000, 1_000_000, 210_000, 1_210_000],
            ['2026-11', 'cost', 'received-documents.payments', 242_000, 200_000, 42_000, 242_000],
            ['2026-11', 'income', 'invoicing.payments', 484_000, 400_000, 84_000, 484_000],
        ], array_map(static fn (AmountSum $sum): array => [$sum->month, $sum->kind->value, $sum->source, $sum->gross->minor(), $sum->net->minor(), $sum->vat->minor(), $sum->counted->minor()], $amounts->sums));
        self::assertSame([1_694_000, 1_400_000, 294_000, 1_694_000], $this->figures($amounts->totalOf(AmountKind::Income)));
        $sources = $this->amounts([
            new FakeAmountSource('received-documents.payments', [$this->amount('received-documents.payments', 'x', AmountKind::Income, '2026-11-02', 100, 100)]),
            new FakeAmountSource('invoicing.payments', [$this->amount('invoicing.payments', 'y', AmountKind::Income, '2026-11-02', 200, 200)]),
        ])->year(2026);
        self::assertSame(['invoicing.payments', 'received-documents.payments'], array_map(static fn (AmountSum $sum): string => $sum->source, $sources->sums), 'Sources sort by key within a month and kind.');
        self::assertSame([242_000, 200_000, 42_000, 242_000], $this->figures($amounts->totalOf(AmountKind::Cost)));
        self::assertCount(4, $amounts->lines);
        self::assertSame([], $amounts->unavailableSources);
        self::assertSame([], $amounts->restrictedSources);
        self::assertSame([], $amounts->unverified, 'All in CZK and no VAT payer: nothing to confirm.');
        self::assertSame([AmountsView::GAP_NON_DEDUCTIBLE_COSTS, AmountsView::GAP_ASSETS], $amounts->knownGaps);
    }

    public function testAnEurPaymentIsConvertedAtTheRateOfItsDay(): void
    {
        $amounts = $this->amounts([
            new FakeAmountSource('invoicing.payments', [
                $this->amount('invoicing.payments', 'eur', AmountKind::Income, '2026-11-13', 100_000, 100_000, Currency::EUR),
                $this->amount('invoicing.payments', 'later', AmountKind::Income, '2026-11-17', 12_100, 10_000, Currency::EUR),
            ]),
            new FakeAmountSource('received-documents.payments', []),
        ])->year(2026);

        // 1,000 EUR at 25.000 on 13 November, not at today's 26.000.
        self::assertSame([2_500_000, 2_500_000, 0, 2_500_000], [$amounts->lines[0]->grossCzk->minor(), $amounts->lines[0]->netCzk->minor(), $amounts->lines[0]->vatCzk->minor(), $amounts->lines[0]->countedCzk->minor()]);
        self::assertSame(Currency::CZK, $amounts->lines[0]->grossCzk->currency());
        self::assertSame([314_600, 260_000, 54_600], [$amounts->lines[1]->grossCzk->minor(), $amounts->lines[1]->netCzk->minor(), $amounts->lines[1]->vatCzk->minor()], 'On the 17th the rate of the 16th holds.');
        self::assertSame(1_000 * 100, $amounts->lines[0]->amount->gross->minor(), 'The amount itself stays in its currency.');
        self::assertSame([AmountsView::UNVERIFIED_FX_PAYMENT_DAY], $amounts->unverified);
        self::assertSame([], $amounts->missingRates);
    }

    public function testACurrencyWithoutARateOnTheDayIsLeftOutAndListed(): void
    {
        $logger = new RecordingLogger();
        $amounts = $this->amounts([
            new FakeAmountSource('invoicing.payments', [
                $this->amount('invoicing.payments', 'usd', AmountKind::Income, '2026-11-13', 100_000, 100_000, Currency::USD),
                $this->amount('invoicing.payments', 'usd2', AmountKind::Income, '2026-11-14', 100_000, 100_000, Currency::USD),
                $this->amount('invoicing.payments', 'czk', AmountKind::Income, '2026-11-13', 50_000, 50_000),
            ]),
            new FakeAmountSource('received-documents.payments', []),
        ], $logger)->year(2026);

        self::assertSame(['USD'], $amounts->missingRates);
        self::assertSame([['warning', 'USD', '2026-11-13'], ['warning', 'USD', '2026-11-14']], array_map(static fn (array $record): array => [$record[0], $record[1]['currency'] ?? null, $record[1]['day'] ?? null], $logger->records), 'Each day without a rate is asked once.');
        self::assertSame(['czk'], array_map(static fn (AmountLine $line): string => $line->amount->subject->id, $amounts->lines));
        self::assertSame(50_000, $amounts->totalOf(AmountKind::Income)->gross->minor());
        self::assertSame([], $amounts->unverified);
    }

    public function testBeforeTheVatPayerDayTheRecordsCountTheVatAndFromItTheyDoNot(): void
    {
        FirmVat::from($this->em, 'no', '2026-01-01');
        FirmVat::from($this->em, 'yes', '2026-11-12');

        $amounts = $this->amounts([
            new FakeAmountSource('invoicing.payments', [
                $this->amount('invoicing.payments', 'before', AmountKind::Income, '2026-11-11', 1_210_000, 1_000_000),
                $this->amount('invoicing.payments', 'from', AmountKind::Income, '2026-11-12', 1_210_000, 1_000_000),
            ]),
            new FakeAmountSource('other.payments', [
                $this->amount('other.payments', 'eur', AmountKind::Income, '2026-11-13', 0, 0, Currency::EUR),
                $this->amount('other.payments', 'eur2', AmountKind::Income, '2026-11-13', 0, 0, Currency::EUR),
            ]),
            new FakeAmountSource('received-documents.payments', [
                $this->amount('received-documents.payments', 'cost', AmountKind::Cost, '2026-11-12', 121_000, 100_000),
            ]),
        ])->year(2026);

        self::assertSame([1_210_000, 1_000_000], array_map(static fn (AmountLine $line): int => $line->countedCzk->minor(), \array_slice($amounts->lines, 0, 2)));
        self::assertSame([2_420_000, 2_000_000, 420_000, 2_210_000], $this->figures($amounts->totalOf(AmountKind::Income)));
        self::assertSame(100_000, $amounts->totalOf(AmountKind::Cost)->counted->minor(), 'The deducted VAT of a cost leaves it too.');
        self::assertSame([AmountsView::UNVERIFIED_VAT_CASH_BASIS, AmountsView::UNVERIFIED_FX_PAYMENT_DAY], $amounts->unverified, 'Each flag once, in the order first met.');
    }

    public function testAPastYearRunsToItsLastDay(): void
    {
        $source = new FakeAmountSource('invoicing.payments', []);

        $amounts = $this->amounts([$source])->year(2025);

        self::assertSame(['2025-01-01', '2025-12-31'], [$amounts->from->format('Y-m-d'), $amounts->to->format('Y-m-d')]);
        self::assertSame('2026-01-01', $this->amounts([])->thisYear()->from->format('Y-m-d'));
    }

    public function testAYearAfterThisOneOrBefore2000IsRefused(): void
    {
        foreach ([2027, 1999] as $year) {
            try {
                $this->amounts([])->year($year);
                self::fail('The year '.$year.' was not refused.');
            } catch (TaxesRefused $refused) {
                self::assertSame(['invalid_year', 'year'], [$refused->reason(), $refused->field()]);
            }
        }
        self::assertSame('2000-01-01', $this->amounts([])->year(2000)->from->format('Y-m-d'));
    }

    public function testWithoutInvoicesReadTheAmountsAreTotalsOnly(): void
    {
        $amounts = $this->amounts([
            new FakeAmountSource('invoicing.payments', [$this->amount('invoicing.payments', 'hidden', AmountKind::Income, '2026-11-02', 121_000, 100_000)], details: false),
            new FakeAmountSource('invoicing.payments', [], details: false),
            new FakeAmountSource('received-documents.payments', [$this->amount('received-documents.payments', 'shown', AmountKind::Cost, '2026-11-02', 1_000, 1_000)]),
        ])->year(2026);

        self::assertSame(['invoicing.payments'], $amounts->restrictedSources);
        self::assertSame(['shown'], array_map(static fn (AmountLine $line): string => $line->amount->subject->id, $amounts->lines));
        self::assertSame(121_000, $amounts->totalOf(AmountKind::Income)->gross->minor(), 'It still counts in the sums.');
        self::assertSame(['received-documents.payments', 'invoicing.payments'], array_map(static fn (AmountSum $sum): string => $sum->source, $amounts->sums), 'Costs sort before income within a month.');
    }

    public function testModuleRunsWithoutInvoicingAndPurchasing(): void
    {
        // Without the modules no source is registered, and the sources expected are named.
        $amounts = $this->amounts([])->year(2026);

        self::assertSame(['invoicing.payments', 'received-documents.payments'], $amounts->unavailableSources);
        self::assertSame([0, 0, 0, 0], $this->figures($amounts->totalOf(AmountKind::Income)));
        self::assertSame([0, 0, 0, 0], $this->figures($amounts->totalOf(AmountKind::Cost)));
        self::assertSame(Currency::CZK, $amounts->totalOf(AmountKind::Cost)->counted->currency());
        self::assertSame([], $amounts->sums);
    }

    public function testAFailingSourceIsUnavailableAndTheOthersStillCount(): void
    {
        $amounts = $this->amounts([
            new FakeAmountSource('invoicing.payments', [], fails: true),
            new FakeAmountSource('invoicing.payments', [], fails: true),
            new FakeAmountSource('received-documents.payments', [$this->amount('received-documents.payments', 'cost', AmountKind::Cost, '2026-11-02', 1_000, 1_000)]),
        ], $logger = new RecordingLogger())->year(2026);

        self::assertSame(['invoicing.payments'], $amounts->unavailableSources, 'A source is listed once.');
        self::assertSame(['error', 'invoicing.payments'], [$logger->records[0][0], $logger->records[0][1]['source'] ?? null]);
        self::assertInstanceOf(\RuntimeException::class, $logger->records[0][1]['exception'] ?? null);
        self::assertSame(1_000, $amounts->totalOf(AmountKind::Cost)->gross->minor());
    }

    public function testAMemberIsRefusedWithOwnerOnly(): void
    {
        $this->signIn($this->member);

        $this->expectExceptionObject(TaxesRefused::ownerOnly());

        $this->amounts([])->year(2026);
    }

    public function testTheToolAnswersTheYearAndSaysWhatTheRecordsCount(): void
    {
        $tool = self::getContainer()->get(TaxAmountsTool::class);

        $output = $tool->call(['year' => 2025]);

        self::assertSame("From 2025-01-01 to 2025-12-31 the records count CZK\u{a0}0.00 of income and CZK\u{a0}0.00 of costs.", $output->summary);
        self::assertSame(TaxesJson::amounts($this->amounts([])->year(2025))['totals'], $output->data['totals'] ?? null);
        self::assertSame('2026-01-01', $tool->call([])->data['from'] ?? null);
        self::assertSame('foresi_tax_amounts', $tool->name());
        self::assertSame('taxes:read', $tool->scope());
        self::assertTrue($tool->staffOnly());
        try {
            $tool->call(['year' => '2026']);
            self::fail('A year as text was taken.');
        } catch (ToolRefusal $refusal) {
            self::assertStringContainsString('"year"', $refusal->getMessage());
        }
        try {
            $tool->call(['year' => 2027]);
            self::fail('A year after this one was taken.');
        } catch (ToolRefusal $refusal) {
            self::assertStringContainsString('year', $refusal->getMessage());
        }
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

    /**
     * @return array{int, int, int, int}
     */
    private function figures(AmountTotals $totals): array
    {
        return [$totals->gross->minor(), $totals->net->minor(), $totals->vat->minor(), $totals->counted->minor()];
    }
}
