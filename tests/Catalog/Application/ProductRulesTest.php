<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Catalog\Application;

use Foresi\Catalog\Application\CatalogRefused;
use Foresi\Catalog\Application\ProductDraft;
use Foresi\Catalog\Application\ProductRules;
use Foresi\Catalog\Contract\BillingKind;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a product may say: a recurring one bills every interval of a unit, a
 * one-off one has no recurrence, the cycles are a positive count or none, and
 * the price is optional.
 */
final class ProductRulesTest extends TestCase
{
    public function testRecurringNeedsUnitAndInterval(): void
    {
        $terms = new ProductRules()->terms(self::draft(billingKind: 'recurring', recurrenceUnit: 'month', interval: 1));
        self::assertSame([BillingKind::Recurring, RecurrenceUnit::Month, 1, null], [$terms->billingKind, $terms->recurrenceUnit, $terms->interval, $terms->cycles]);

        $this->assertRefused('recurrence_unit_required', 'recurrenceUnit', self::draft(billingKind: 'recurring', interval: 1));
        $this->assertRefused('interval_required', 'interval', self::draft(billingKind: 'recurring', recurrenceUnit: 'month'));
        $this->assertRefused('recurrence_unit_unknown', 'recurrenceUnit', self::draft(billingKind: 'recurring', recurrenceUnit: 'fortnight', interval: 1));
        $this->assertRefused('interval_invalid', 'interval', self::draft(billingKind: 'recurring', recurrenceUnit: 'month', interval: 0));
        $this->assertRefused('interval_invalid', 'interval', self::draft(billingKind: 'recurring', recurrenceUnit: 'month', interval: 100));
    }

    public function testOneOffRefusesRecurrence(): void
    {
        $terms = new ProductRules()->terms(self::draft());
        self::assertSame([BillingKind::OneOff, null, null, null], [$terms->billingKind, $terms->recurrenceUnit, $terms->interval, $terms->cycles]);

        $this->assertRefused('one_off_recurrence', 'recurrenceUnit', self::draft(recurrenceUnit: 'month'));
        $this->assertRefused('one_off_recurrence', 'interval', self::draft(interval: 1));
        $this->assertRefused('one_off_recurrence', 'cycles', self::draft(cycles: 3));
        $this->assertRefused('billing_kind_unknown', 'billingKind', self::draft(billingKind: 'time_based'));
    }

    /**
     * @return iterable<string, array{?int, ?string}>
     */
    public static function cycles(): iterable
    {
        yield 'open-ended' => [null, null];
        yield 'one' => [1, null];
        yield 'the most' => [999, null];
        yield 'zero' => [0, 'cycles_invalid'];
        yield 'negative' => [-1, 'cycles_invalid'];
        yield 'too many' => [1000, 'cycles_invalid'];
    }

    #[DataProvider('cycles')]
    public function testCyclesPositiveOrNull(?int $cycles, ?string $refusal): void
    {
        $draft = self::draft(billingKind: 'recurring', recurrenceUnit: 'year', interval: 1, cycles: $cycles);
        if (null !== $refusal) {
            $this->assertRefused($refusal, 'cycles', $draft);

            return;
        }
        self::assertSame($cycles, new ProductRules()->terms($draft)->cycles);
    }

    public function testPriceOptional(): void
    {
        $rules = new ProductRules();
        self::assertNull($rules->terms(self::draft())->unitPrice);

        $priced = $rules->terms(self::draft(unitPrice: '1500.50', currency: 'EUR'))->unitPrice;
        self::assertSame([150050, 'EUR'], [$priced?->minor(), $priced?->currency()->value]);
        self::assertSame(0, $rules->terms(self::draft(unitPrice: '0'))->unitPrice?->minor());

        $this->assertRefused('unit_price_invalid', 'unitPrice', self::draft(unitPrice: '12,5'));
        $this->assertRefused('unit_price_negative', 'unitPrice', self::draft(unitPrice: '-1'));
        $this->assertRefused('currency_unknown', 'currency', self::draft(unitPrice: '10', currency: 'GBP'));
    }

    public function testNameUnitAndDescriptionAreTrimmedAndBounded(): void
    {
        $terms = new ProductRules()->terms(self::draft(name: '  Roof audit  ', unit: ' hour ', description: "  Line one\n"));
        self::assertSame(['Roof audit', 'hour', 'Line one'], [$terms->name, $terms->unit, $terms->description]);

        $this->assertRefused('name_required', 'name', self::draft(name: '  '));
        $this->assertRefused('name_too_long', 'name', self::draft(name: str_repeat('a', 201)));
        $this->assertRefused('unit_too_long', 'unit', self::draft(unit: str_repeat('a', 33)));
        $this->assertRefused('description_too_long', 'description', self::draft(description: str_repeat('a', 2001)));
    }

    private static function draft(
        string $name = 'Roof audit',
        string $unit = 'piece',
        ?string $unitPrice = null,
        string $currency = 'CZK',
        string $billingKind = 'one_off',
        ?string $recurrenceUnit = null,
        ?int $interval = null,
        ?int $cycles = null,
        string $description = '',
    ): ProductDraft {
        return new ProductDraft($name, $description, $unit, $unitPrice, $currency, $billingKind, $recurrenceUnit, $interval, $cycles);
    }

    private function assertRefused(string $reason, string $field, ProductDraft $draft): void
    {
        try {
            new ProductRules()->terms($draft);
            self::fail(\sprintf('The draft was not refused for %s.', $reason));
        } catch (CatalogRefused $refused) {
            self::assertSame([$reason, $field], [$refused->reason(), $refused->field()]);
        }
    }
}
