<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Integration\CashFlow;

use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\CashFlow\Contract\ReadFlow;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\ForecastAmount;
use Foresi\TaxesAndContributions\Contract\ForecastCertainty;
use Foresi\TaxesAndContributions\Integration\CashFlow\ForecastAmounts;
use PHPUnit\Framework\TestCase;

/**
 * How a flow of the forecast becomes a forecast amount: what is asked, and
 * which side of the records each flow goes to, in CZK.
 */
final class ForecastAmountsMappingTest extends TestCase
{
    public function testTheQueryLeavesOutTheOwnSourceTheStatutoryCategoriesAndEstimates(): void
    {
        $reader = new FakeForecastReader([]);
        $from = new \DateTimeImmutable('2026-11-20');
        $to = new \DateTimeImmutable('2026-12-31');

        $read = new ForecastAmounts($reader)->between($from, $to);

        self::assertCount(1, $reader->queries);
        $query = $reader->queries[0];
        self::assertSame([$from, $to], [$query->from, $query->to]);
        self::assertSame(['taxes-and-contributions.estimate'], $query->excludeSources);
        self::assertSame([FlowCategory::Taxes, FlowCategory::Insurance, FlowCategory::Vat, FlowCategory::Loans, FlowCategory::OwnerDraws], $query->excludeCategories);
        self::assertSame([FlowCertainty::Document, FlowCertainty::Schedule, FlowCertainty::Plan], $query->certainties);
        self::assertSame([[], ['purchasing.received_documents'], ['invoicing.invoices'], true], [$read->amounts, $read->unavailableSources, $read->restrictedSources, $read->incomplete]);
    }

    public function testEachFlowGoesToItsSideInCzkAndAnEstimateIsNoFact(): void
    {
        $reader = new FakeForecastReader([
            $this->flow(FlowDirection::In, FlowCategory::Revenue, FlowCertainty::Document, 'invoicing.invoices'),
            $this->flow(FlowDirection::Out, FlowCategory::Revenue, FlowCertainty::Document, 'invoicing.invoices'),
            $this->flow(FlowDirection::Out, FlowCategory::Software, FlowCertainty::Estimate, 'other.estimate'),
            $this->flow(FlowDirection::Out, FlowCategory::Purchases, FlowCertainty::Document, 'purchasing.received_documents', subject: false),
            $this->flow(FlowDirection::In, FlowCategory::Purchases, FlowCertainty::Document, 'purchasing.received_documents'),
            $this->flow(FlowDirection::In, FlowCategory::Other, FlowCertainty::Schedule, 'invoicing.recurring_invoices'),
            $this->flow(FlowDirection::Out, FlowCategory::Rent, FlowCertainty::Plan, 'cash-flow.planned_items'),
        ]);

        $amounts = new ForecastAmounts($reader)->between(new \DateTimeImmutable('2026-11-20'), new \DateTimeImmutable('2026-12-31'));

        self::assertSame([
            [AmountKind::Income, 2_420, ForecastCertainty::Document, 'invoicing.invoices'],
            [AmountKind::Income, -2_420, ForecastCertainty::Document, 'invoicing.invoices'],
            [AmountKind::Cost, 2_420, ForecastCertainty::Document, 'purchasing.received_documents'],
            [AmountKind::Cost, -2_420, ForecastCertainty::Document, 'purchasing.received_documents'],
            [AmountKind::Income, 2_420, ForecastCertainty::Schedule, 'invoicing.recurring_invoices'],
            [AmountKind::Cost, 2_420, ForecastCertainty::Plan, 'cash-flow.planned_items'],
        ], array_map(static fn (ForecastAmount $amount): array => [$amount->kind, $amount->grossCzk->minor(), $amount->certainty, $amount->source], $amounts->amounts));
        self::assertSame(['invoice', 'd1', '/api/v1/invoices/d1', '2026-12-10'], [$amounts->amounts[0]->subject?->type, $amounts->amounts[0]->subject?->id, $amounts->amounts[0]->subject?->url, $amounts->amounts[0]->on->format('Y-m-d')]);
        self::assertNull($amounts->amounts[2]->subject, 'A flow the caller may not read comes without its document.');
        self::assertSame(Currency::CZK, $amounts->amounts[1]->grossCzk->currency());
    }

    private function flow(FlowDirection $direction, FlowCategory $category, FlowCertainty $certainty, string $source, bool $subject = true): ReadFlow
    {
        return new ReadFlow($source, $subject ? new FlowSubject('invoice', 'd1', '/api/v1/invoices/d1') : null, $direction, Money::ofMinor(100, Currency::EUR), Money::ofMinor(2_420, Currency::CZK), new \DateTimeImmutable('2026-12-10'), $category, $certainty, []);
    }
}
