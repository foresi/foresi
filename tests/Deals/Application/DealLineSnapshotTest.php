<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Application;

use Foresi\Catalog\Application\ArchiveProductInput;
use Foresi\Catalog\Application\CreateProductInput;
use Foresi\Catalog\Application\ProductWrites;
use Foresi\Catalog\Application\UpdateProductInput;
use Foresi\Catalog\Contract\BillingKind;
use Foresi\Deals\Application\Api\AddDealLineInput;
use Foresi\Deals\Application\Api\RemoveDealLineInput;
use Foresi\Deals\Application\Api\ReorderDealLinesInput;
use Foresi\Deals\Application\Api\UpdateDealLineInput;
use Foresi\Deals\Domain\Deal;
use Foresi\Deals\Domain\DealLine;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use Foresi\Tests\Deals\Support\SalesTestCase;

/**
 * A line from a product copies what the product says when it is added; a
 * typed line says it itself, by the catalog's rules.
 */
final class DealLineSnapshotTest extends SalesTestCase
{
    public function testEditingProductLeavesLineUnchanged(): void
    {
        $products = self::getContainer()->get(ProductWrites::class);
        $product = $products->create(new CreateProductInput('Roof maintenance', 'recurring', 'month', '1500.00', 'EUR', 'month', 1, 12));
        $deal = $this->deal();

        $line = $this->lines()->add(new AddDealLineInput($deal->id(), $product->id(), quantity: '2'));
        $products->update(new UpdateProductInput($product->id(), name: 'Roof care', unit: 'visit', unitPrice: '2000.00', currency: 'CZK', recurrenceUnit: 'year', interval: 2, cycles: null));

        $kept = $this->reload(DealLine::class, $line->id());
        self::assertSame($product->id(), $kept->productId());
        self::assertSame('Roof maintenance', $kept->name());
        self::assertSame('month', $kept->unit());
        self::assertSame('2.000', $kept->quantity());
        self::assertSame(150_000, $kept->unitPrice()?->minor());
        self::assertSame(Currency::EUR, $kept->unitPrice()->currency());
        self::assertSame(BillingKind::Recurring, $kept->billingKind());
        self::assertSame(RecurrenceUnit::Month, $kept->recurrenceUnit());
        self::assertSame(1, $kept->interval());
        self::assertSame(12, $kept->cycles());
        self::assertSame(['EUR' => 300_000], array_map(static fn (\Foresi\Shared\Domain\Money $money): int => $money->minor(), $this->reads()->deal($deal->id())->value->monthly));
    }

    public function testTypedLineWithoutProduct(): void
    {
        $deal = $this->deal();

        $line = $this->lines()->add(new AddDealLineInput($deal->id(), name: '  Site survey ', unit: 'hour', quantity: '1.5', unitPrice: '900.50', currency: 'CZK'));

        $kept = $this->reload(DealLine::class, $line->id());
        self::assertNull($kept->productId());
        self::assertSame('Site survey', $kept->name());
        self::assertSame('hour', $kept->unit());
        self::assertSame('1.500', $kept->quantity());
        self::assertSame(90_050, $kept->unitPrice()?->minor());
        self::assertSame(BillingKind::OneOff, $kept->billingKind());
        self::assertNull($kept->recurrenceUnit());
        self::assertNull($kept->interval());
        self::assertNull($kept->cycles());
        self::assertSame(0, $kept->position());

        $unpriced = $this->lines()->add(new AddDealLineInput($deal->id(), name: 'Support', billingKind: 'recurring', recurrenceUnit: 'week', interval: 1));
        self::assertNull($unpriced->unitPrice());
        self::assertSame('', $unpriced->unit());
        self::assertSame('1.000', $unpriced->quantity());
        self::assertSame(1, $unpriced->position());
        self::assertSame(['CZK' => 135_075], array_map(static fn (\Foresi\Shared\Domain\Money $money): int => $money->minor(), $this->reads()->deal($deal->id())->value->oneOff));
        self::assertFalse($this->reads()->deal($deal->id())->value->approximate, 'A line without a price adds nothing.');
    }

    public function testATimeBasedLineKeepsNoQuantityUntilOneIsGiven(): void
    {
        $deal = $this->deal();

        $line = $this->lines()->add(new AddDealLineInput($deal->id(), name: 'Development', unitPrice: '1200.00', billingKind: 'time_based'));
        $kept = $this->reload(DealLine::class, $line->id());
        self::assertNull($kept->quantity());
        self::assertSame(['h', BillingKind::TimeBased, 120_000], [$kept->unit(), $kept->billingKind(), $kept->unitPrice()?->minor()]);

        $this->lines()->update(new UpdateDealLineInput($deal->id(), $line->id(), quantity: '40'));
        self::assertSame('40.000', $this->reload(DealLine::class, $line->id())->quantity());

        $this->lines()->update(new UpdateDealLineInput($deal->id(), $line->id(), quantity: null));
        self::assertNull($this->reload(DealLine::class, $line->id())->quantity());

        $this->assertRefused('quantity_required', fn (): DealLine => $this->lines()->update(new UpdateDealLineInput($deal->id(), $line->id(), unit: 'piece', billingKind: 'one_off')));
        $this->assertRefused('time_based_unit', fn (): DealLine => $this->lines()->add(new AddDealLineInput($deal->id(), name: 'Development', unit: 'day', unitPrice: '1.00', billingKind: 'time_based')));
        $this->assertRefused('rate_required', fn (): DealLine => $this->lines()->add(new AddDealLineInput($deal->id(), name: 'Development', billingKind: 'time_based')));
        $this->assertRefused('time_based_recurrence', fn (): DealLine => $this->lines()->add(new AddDealLineInput($deal->id(), name: 'Development', unitPrice: '1.00', billingKind: 'time_based', interval: 1)));
        $this->assertRefused('quantity_required', fn (): DealLine => $this->lines()->update(new UpdateDealLineInput($deal->id(), $this->typedLine($deal)->id(), quantity: null)));
    }

    public function testATimeBasedProductGivesALineWithoutQuantity(): void
    {
        $products = self::getContainer()->get(ProductWrites::class);
        $product = $products->create(new CreateProductInput('Development', billingKind: 'time_based', unitPrice: '1200.00'));
        $deal = $this->deal();

        $line = $this->lines()->add(new AddDealLineInput($deal->id(), $product->id()));
        self::assertNull($this->reload(DealLine::class, $line->id())->quantity());
        $given = $this->lines()->add(new AddDealLineInput($deal->id(), $product->id(), quantity: '8'));
        self::assertSame(['h', '8.000'], [$given->unit(), $given->quantity()]);
        self::assertSame([], $this->reads()->deal($deal->id())->value->monthly);
    }

    public function testTypedLineFollowsTheCatalogRules(): void
    {
        $deal = $this->deal();
        $add = fn (AddDealLineInput $input): DealLine => $this->lines()->add($input);

        $this->assertRefused('name_required', fn (): DealLine => $add(new AddDealLineInput($deal->id())));
        $this->assertRefused('name_too_long', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: str_repeat('a', DealLine::MAX_NAME + 1))));
        $this->assertRefused('unit_too_long', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: 'Audit', unit: str_repeat('a', DealLine::MAX_UNIT + 1))));
        $this->assertRefused('quantity_invalid', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: 'Audit', quantity: '1.2345')));
        $this->assertRefused('quantity_invalid', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: 'Audit', quantity: '-1')));
        $this->assertRefused('quantity_not_positive', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: 'Audit', quantity: '0.000')));
        $this->assertRefused('unit_price_invalid', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: 'Audit', unitPrice: '-5.00')));
        $this->assertRefused('unit_price_invalid', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: 'Audit', unitPrice: '5.001')));
        $this->assertRefused('currency_unknown', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: 'Audit', unitPrice: '5.00', currency: 'GBP')));
        $this->assertRefused('billing_kind_unknown', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: 'Audit', billingKind: 'hourly')));
        $this->assertRefused('one_off_recurrence', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: 'Audit', interval: 1)));
        $this->assertRefused('recurrence_unit_required', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: 'Audit', billingKind: 'recurring', interval: 1)));
        $this->assertRefused('recurrence_unit_unknown', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: 'Audit', billingKind: 'recurring', recurrenceUnit: 'hour', interval: 1)));
        $this->assertRefused('interval_required', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: 'Audit', billingKind: 'recurring', recurrenceUnit: 'month')));
        $this->assertRefused('interval_invalid', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: 'Audit', billingKind: 'recurring', recurrenceUnit: 'month', interval: 100)));
        $this->assertRefused('interval_invalid', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: 'Audit', billingKind: 'recurring', recurrenceUnit: 'month', interval: 0)));
        $this->assertRefused('cycles_invalid', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: 'Audit', billingKind: 'recurring', recurrenceUnit: 'month', interval: 1, cycles: 1000)));
        $this->assertRefused('cycles_invalid', fn (): DealLine => $add(new AddDealLineInput($deal->id(), name: 'Audit', billingKind: 'recurring', recurrenceUnit: 'month', interval: 1, cycles: 0)));
        self::assertSame([], $this->store()->linesOf($deal->id()));
        self::assertSame([], $this->events('sales.deal.updated'));
    }

    public function testALineFromAProductTakesOnlyTheQuantity(): void
    {
        $products = self::getContainer()->get(ProductWrites::class);
        $product = $products->create(new CreateProductInput('Roof audit', unit: 'piece', unitPrice: '12000.00'));
        $deal = $this->deal();

        $refused = $this->assertRefused('product_line_typed', fn (): DealLine => $this->lines()->add(new AddDealLineInput($deal->id(), $product->id(), unitPrice: '1.00')));
        self::assertSame('unitPrice', $refused->field());
        $this->assertRefused('product_line_typed', fn (): DealLine => $this->lines()->add(new AddDealLineInput($deal->id(), $product->id(), name: 'Other')));
        $this->assertRefused('quantity_invalid', fn (): DealLine => $this->lines()->add(new AddDealLineInput($deal->id(), $product->id(), quantity: 'two')));
        $this->assertRefused('product_unknown', fn (): DealLine => $this->lines()->add(new AddDealLineInput($deal->id(), '0192f1a4-0000-7000-8000-000000000000')));
        $products->archive(new ArchiveProductInput($product->id()));
        $this->assertRefused('product_archived', fn (): DealLine => $this->lines()->add(new AddDealLineInput($deal->id(), $product->id())));
    }

    public function testUpdateRemoveAndReorderLines(): void
    {
        $deal = $this->deal();
        $audit = $this->typedLine($deal, 'Audit');
        $survey = $this->typedLine($deal, 'Survey', '500.00');
        $report = $this->typedLine($deal, 'Report', null);

        $updated = $this->lines()->update(new UpdateDealLineInput($deal->id(), $survey->id(), quantity: '3', billingKind: 'recurring', recurrenceUnit: 'month', interval: 1));
        self::assertSame('Survey', $updated->name(), 'What is not given stays.');
        self::assertSame('3.000', $updated->quantity());
        self::assertSame(50_000, $updated->unitPrice()?->minor());
        self::assertSame(RecurrenceUnit::Month, $updated->recurrenceUnit());
        $oneOff = $this->lines()->update(new UpdateDealLineInput($deal->id(), $survey->id(), unitPrice: null, billingKind: 'one_off'));
        self::assertNull($oneOff->recurrenceUnit(), 'A line made one-off keeps no recurrence.');
        self::assertNull($oneOff->interval());
        self::assertNull($oneOff->unitPrice());
        $this->assertRefused('one_off_recurrence', fn (): DealLine => $this->lines()->update(new UpdateDealLineInput($deal->id(), $survey->id(), cycles: 3)));
        $this->assertRefused('not_found', fn (): DealLine => $this->lines()->update(new UpdateDealLineInput($deal->id(), $deal->id(), name: 'Other')));

        $this->lines()->reorder(new ReorderDealLinesInput($deal->id(), [$report->id(), $audit->id(), $survey->id()]));
        self::assertSame(['Report', 'Audit', 'Survey'], $this->names($deal));
        self::assertSame([0, 1, 2], array_map(static fn (DealLine $line): int => $line->position(), $this->store()->linesOf($deal->id())));
        $this->assertRefused('order_mismatch', fn (): Deal => $this->lines()->reorder(new ReorderDealLinesInput($deal->id(), [$report->id(), $audit->id()])));
        $this->assertRefused('order_mismatch', fn (): Deal => $this->lines()->reorder(new ReorderDealLinesInput($deal->id(), [$report->id(), $audit->id(), $audit->id()])));
        $this->assertRefused('order_mismatch', fn (): Deal => $this->lines()->reorder(new ReorderDealLinesInput($deal->id(), [$report->id(), $audit->id(), $survey->id(), $deal->id()])));

        $this->lines()->remove(new RemoveDealLineInput($deal->id(), $report->id()));
        self::assertSame(['Audit', 'Survey'], $this->names($deal));
        self::assertSame([0, 1], array_map(static fn (DealLine $line): int => $line->position(), $this->store()->linesOf($deal->id())));
        $this->assertRefused('not_found', fn (): Deal => $this->lines()->remove(new RemoveDealLineInput($deal->id(), $report->id())));

        // Each change of a line is a change of its deal.
        self::assertCount(7, $this->events('sales.deal.updated'));
        self::assertSame(['deal_id' => $deal->id(), 'status' => 'open', 'stage_id' => $this->stages['inquiry']->id(), 'custom_fields' => []], $this->events('sales.deal.updated')[0]);
    }

    /**
     * @return list<string>
     */
    private function names(Deal $deal): array
    {
        $this->em->clear();

        return array_map(static fn (DealLine $line): string => $line->name(), $this->store()->linesOf($deal->id()));
    }
}
