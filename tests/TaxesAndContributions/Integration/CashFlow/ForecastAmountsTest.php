<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Integration\CashFlow;

use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\ForecastReads;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Infrastructure\Source\PlannedItemFlows;
use Foresi\Country\Integration\TaxesAndContributions\CzechRepublic\CzechSoleTrader;
use Foresi\ExchangeRate\Application\ExchangeRates;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Application\Amounts;
use Foresi\TaxesAndContributions\Application\Estimates;
use Foresi\TaxesAndContributions\Application\ProfileReads;
use Foresi\TaxesAndContributions\Application\YearEndForecast;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\AmountsView;
use Foresi\TaxesAndContributions\Contract\AmountTotals;
use Foresi\TaxesAndContributions\Contract\EstimateView;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Contract\ForecastCertainty;
use Foresi\TaxesAndContributions\Contract\ForecastView;
use Foresi\TaxesAndContributions\Contract\RegimeResult;
use Foresi\TaxesAndContributions\Integration\CashFlow\ForecastAmounts;
use Foresi\Tests\CashFlow\Support\FakeSource;
use Foresi\Tests\Invoicing\Application\AdvanceTestCase;
use Foresi\Tests\TaxesAndContributions\Support\FirmVat;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The year end read from the real Cash flow forecast (B1, B2): an open
 * invoice counts what is still open on it, in its own VAT ratio, while what
 * was paid counts to date; the forecast never asks this module's own source
 * and leaves out the levies, VAT, loans and owner draws.
 */
final class ForecastAmountsTest extends AdvanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $user = new User('owner@example.com', 'hash', 'Example Owner');
        $this->em->persist($user);
        $this->em->persist(new Membership($user, Role::Owner));
        $this->em->flush();
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));
        FirmVat::from($this->em, 'yes', '2026-01-01');
    }

    public function testAPartlyPaidInvoiceCountsItsPaidPartToDateAndItsRestInTheForecast(): void
    {
        // 10,000 + 2,100 VAT, half of it paid.
        $invoice = $this->issue->issue($this->input([$this->line('10000.00')], ['dueDate' => new \DateTimeImmutable('2026-12-10')]));
        $this->pay($invoice, '6050.00', '2026-11-10');
        $this->em->clear();

        $estimate = self::getContainer()->get(Estimates::class)->of(2026);

        self::assertSame([605_000, 500_000, 105_000, 500_000], $this->figures($estimate->amounts?->totalOf(AmountKind::Income)), 'To date: the 6,050 paid, 5,000 of it net.');
        self::assertInstanceOf(ForecastView::class, $estimate->forecast);
        self::assertSame([605_000, 500_000, 105_000, 500_000], $this->figures($estimate->forecast->totalOfCertainty(ForecastCertainty::Document, AmountKind::Income)), 'The forecast: the 6,050 still open, 5,000 of it net.');
        self::assertSame('invoicing.invoices', $estimate->forecast->sums[0]->source);
        self::assertSame('2026-12', $estimate->forecast->sums[0]->month);
        self::assertSame([], $estimate->forecast->flags);
        self::assertSame(500_000, $estimate->toDate?->method(ExpenseMethod::Actual)->totals->income->minor());
        self::assertSame(1_000_000, $estimate->yearEnd?->method(ExpenseMethod::Actual)->totals->income->minor());
    }

    public function testForecastExcludesItsOwnSourceAndStatutoryCategories(): void
    {
        $writes = self::getContainer()->get(PlannedItemWrites::class);
        foreach (['taxes', 'insurance', 'vat', 'loans', 'owner_draws'] as $category) {
            $writes->create(new CreatePlannedItemInput('out', 'Advance '.$category, $category, '5005.00', '2026-12-20'));
        }
        $writes->create(new CreatePlannedItemInput('in', 'Consulting not invoiced yet', 'revenue', '1000.00', '2026-12-15'));
        $writes->create(new CreatePlannedItemInput('out', 'Office rent', 'rent', '300.00', '2026-12-01'));
        $own = new FakeSource(ForecastAmounts::OWN_SOURCE, [new ExpectedFlow(ForecastAmounts::OWN_SOURCE, new FlowSubject('tax_estimate', '2026'), FlowDirection::Out, Money::ofMinor(500_500, Currency::CZK), new \DateTimeImmutable('2026-12-31'), FlowCategory::Other, FlowCertainty::Plan, 'Social advance')]);

        $estimate = $this->estimates([$own, self::getContainer()->get(PlannedItemFlows::class)])->of(2026);

        self::assertSame([], $own->asked, 'The estimate source is never called, so the estimate never reads itself.');
        self::assertInstanceOf(ForecastView::class, $estimate->forecast);
        self::assertSame([100_000, 100_000, 0, 100_000], $this->figures($estimate->forecast->totalOfCertainty(ForecastCertainty::Plan, AmountKind::Income)));
        self::assertSame([30_000, 30_000, 0, 30_000], $this->figures($estimate->forecast->totalOf(AmountKind::Cost)), 'Only the rent: the 5,005 of taxes and the other statutory items are absent.');
        self::assertSame([ForecastView::FLAG_PLAN_NO_VAT_SPLIT], $estimate->forecast->flags);
        self::assertInstanceOf(RegimeResult::class, $estimate->yearEnd);
        self::assertSame(30_000, $estimate->yearEnd->method(ExpenseMethod::Actual)->totals->costs->minor());
        self::assertSame(100_000, $estimate->yearEnd->method(ExpenseMethod::Actual)->totals->income->minor());
        self::assertNull($estimate->yearEndReason);
        self::assertNotContains(EstimateView::WARNING_FORECAST_INCOMPLETE, $estimate->warnings);
    }

    public function testTaxesYearEndCountsAPlannedItemAtNet(): void
    {
        $writes = self::getContainer()->get(PlannedItemWrites::class);
        $writes->create(new CreatePlannedItemInput('in', 'Consulting not invoiced yet', 'revenue', '12100.00', '2026-12-15', vatMode: 'included', vatRate: '21'));
        $writes->create(new CreatePlannedItemInput('out', 'Refund to a client', 'revenue', '1210.00', '2026-12-16', vatMode: 'included', vatRate: '21'));
        $writes->create(new CreatePlannedItemInput('out', 'Office rent', 'rent', '1000.00', '2026-12-01', vatMode: 'excluded', vatRate: '21'));
        $writes->create(new CreatePlannedItemInput('out', 'Software', 'software', '300.00', '2026-12-01'));

        $estimate = $this->estimates([self::getContainer()->get(PlannedItemFlows::class)])->of(2026);

        self::assertInstanceOf(ForecastView::class, $estimate->forecast);
        self::assertSame([1_089_000, 900_000, 189_000, 900_000], $this->figures($estimate->forecast->totalOfCertainty(ForecastCertainty::Plan, AmountKind::Income)), 'The consulting counts its 10,000 net, the refund takes 1,000 net back.');
        self::assertSame([151_000, 130_000, 21_000, 130_000], $this->figures($estimate->forecast->totalOf(AmountKind::Cost)), 'The rent counts its 1,000 net; the software states no VAT and counts as stated.');
        self::assertSame([ForecastView::FLAG_PLAN_NO_VAT_SPLIT], $estimate->forecast->flags, 'Only the software is not split.');
        self::assertSame([AmountsView::UNVERIFIED_VAT_CASH_BASIS], $estimate->forecast->unverified);
        self::assertInstanceOf(RegimeResult::class, $estimate->yearEnd);
        self::assertSame(900_000, $estimate->yearEnd->method(ExpenseMethod::Actual)->totals->income->minor());
        self::assertSame(130_000, $estimate->yearEnd->method(ExpenseMethod::Actual)->totals->costs->minor());
    }

    /**
     * @param list<\Foresi\CashFlow\Contract\CashFlowSource> $sources
     */
    private function estimates(array $sources): Estimates
    {
        $container = self::getContainer();
        $reads = new ForecastReads($container->get(CashFlowAccess::class), $container->get(CashFlowStore::class), $container->get(InstanceClock::class), $container->get(ExchangeRates::class), sources: $sources);

        return new Estimates($container->get(ProfileReads::class), $container->get(Amounts::class), [new CzechSoleTrader()], $container->get(InstanceClock::class), [new ForecastAmounts($reads)], $container->get(YearEndForecast::class));
    }

    /**
     * @return array{int, int, int, int}
     */
    private function figures(?AmountTotals $totals): array
    {
        self::assertInstanceOf(AmountTotals::class, $totals);

        return [$totals->gross->minor(), $totals->net->minor(), $totals->vat->minor(), $totals->counted->minor()];
    }
}
