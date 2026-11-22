<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Screen;

use Foresi\Catalog\Application\ProductRules;
use Foresi\Catalog\Contract\BillingKind;
use Foresi\Catalog\Domain\Product;
use Foresi\Shared\Domain\Money;
use Foresi\Ui\Format\MoneyText;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How the screens of the catalog put a product into words: its price, how it
 * is billed, its facts, the values of its form and the options of the form's
 * choices.
 */
final readonly class ProductScreen
{
    public function __construct(private TranslatorInterface $translator, private MoneyText $money)
    {
    }

    /**
     * The price per unit, or nothing when the price is agreed each time.
     */
    public function price(Product $product): string
    {
        $price = $product->unitPrice();

        return $price instanceof Money ? $this->money->shown($price) : '';
    }

    /**
     * "One-off", or "Every 3 months, 12 times" for a recurring product.
     */
    public function billing(Product $product): string
    {
        $unit = $product->recurrenceUnit();
        if (BillingKind::OneOff === $product->billingKind() || !$unit instanceof \Foresi\Shared\Domain\Schedule\RecurrenceUnit) {
            return $this->translator->trans('catalog.billing_kind.one_off');
        }
        $every = $this->translator->trans('catalog.product.every.'.$unit->value, ['count' => $product->interval() ?? 1]);
        $cycles = $product->cycles();

        return null === $cycles ? $this->translator->trans('catalog.product.billing_open', ['every' => $every]) : $this->translator->trans('catalog.product.billing_cycles', ['every' => $every, 'count' => $cycles]);
    }

    /**
     * @return list<array{label: string, value: ?string, empty?: string}>
     */
    public function details(Product $product): array
    {
        return [
            ['label' => $this->trans('catalog.product.field.unit'), 'value' => '' === $product->unit() ? null : $product->unit(), 'empty' => $this->trans('catalog.product.none.unit')],
            ['label' => $this->trans('catalog.product.field.unit_price'), 'value' => '' === $this->price($product) ? null : $this->price($product), 'empty' => $this->trans('catalog.product.none.unit_price')],
            ['label' => $this->trans('catalog.product.field.billing'), 'value' => $this->billing($product)],
            ['label' => $this->trans('catalog.product.field.description'), 'value' => '' === $product->description() ? null : $product->description(), 'empty' => $this->trans('catalog.product.none.description')],
        ];
    }

    /**
     * The form's values: those of the product, or of a new one-off product.
     *
     * @return array<string, string>
     */
    public function values(?Product $product): array
    {
        $price = $product?->unitPrice();

        return [
            'name' => $product?->name() ?? '',
            'description' => $product?->description() ?? '',
            'unit' => $product?->unit() ?? '',
            'unitPrice' => $price instanceof Money ? $this->decimal($price) : '',
            'currency' => $price?->currency()->value ?? 'CZK',
            'billingKind' => $product?->billingKind()->value ?? BillingKind::OneOff->value,
            'recurrenceUnit' => ($product instanceof Product ? $product->recurrenceUnit()?->value : null) ?? 'month',
            'interval' => (string) ($product?->interval() ?? 1),
            'cycles' => null === $product?->cycles() ? '' : (string) $product->cycles(),
        ];
    }

    /**
     * @return array{billingKinds: array<string, string>, units: array<string, string>, currencies: array<string, string>}
     */
    public function options(): array
    {
        return [
            'billingKinds' => array_combine(ProductRules::BILLING_KINDS, array_map(fn (string $kind): string => $this->trans('catalog.billing_kind.'.$kind), ProductRules::BILLING_KINDS)),
            'units' => array_combine(ProductRules::UNITS, array_map(fn (string $unit): string => $this->trans('catalog.product.unit_of_time.'.$unit), ProductRules::UNITS)),
            'currencies' => array_combine(ProductRules::CURRENCIES, ProductRules::CURRENCIES),
        ];
    }

    private function decimal(Money $money): string
    {
        $minor = $money->minor();

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', \STR_PAD_LEFT);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }
}
