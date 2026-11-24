<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Api;

use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Contract\VatRegime;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * The lines of a document as the API writes them: objects of text, unit_price,
 * quantity, unit, price_type, vat, discount and supply_code, every value a
 * string. Invoices and recurring templates read them alike, and a template
 * answers its lines in the same shape, so an agent can send them back changed.
 * A stored line also answers its id (and an invoice line its source_line_id);
 * both are read and ignored, as lines are revised by position and the ids are
 * never set from input.
 */
final class ApiLines
{
    private const string QUANTITY = '/^-?\d{1,9}(?:\.\d{1,4})?$/D';
    private const string DISCOUNT = '/^\d{1,3}(?:\.\d{1,2})?$/D';
    private const array LINE_KEYS = ['text', 'unit_price', 'quantity', 'unit', 'price_type', 'vat', 'discount', 'supply_code'];
    private const array IGNORED_KEYS = ['id', 'source_line_id'];
    private const array VAT = ['base', 'reduced', 'second_reduced', 'reverse_charge', 'exempt', 'none'];

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<LineInput>
     *
     * @throws InvoicingRefused when a line cannot be read
     */
    public static function read(array $rows, Currency $currency, VatRegime $regime, bool $reverseCharge): array
    {
        $lines = [];
        foreach ($rows as $index => $row) {
            $lines[] = self::line($row, $index, $currency, $regime, $reverseCharge);
        }

        return $lines;
    }

    /**
     * A line in the shape read() takes.
     *
     * @return array{text: string, unit_price: string, quantity: string, unit: string, price_type: string, vat: string, discount: string, supply_code: ?string}
     */
    public static function fields(string $text, Quantity $quantity, string $unit, Money $price, PriceType $priceType, TaxTreatment $treatment, ?VatRateKind $rateKind, ?string $supplyCode, Percent $discount): array
    {
        return [
            'text' => $text,
            'unit_price' => self::decimal($price),
            'quantity' => $quantity->toDecimal(),
            'unit' => $unit,
            'price_type' => $priceType->value,
            'vat' => self::vatName($treatment, $rateKind),
            'discount' => $discount->decimal(),
            'supply_code' => $supplyCode,
        ];
    }

    /**
     * An amount as a decimal of the places of its currency, such as "1500.00".
     */
    public static function decimal(Money $money): string
    {
        $places = $money->currency()->minorUnits();
        $minor = abs($money->minor());
        $sign = $money->minor() < 0 ? '-' : '';
        if (0 === $places) {
            return $sign.$minor;
        }
        $digits = str_pad((string) $minor, $places + 1, '0', \STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$places).'.'.substr($digits, -$places);
    }

    /**
     * @param array<array-key, mixed> $row a JSON object, whose keys may be digits
     */
    private static function line(array $row, int $index, Currency $currency, VatRegime $regime, bool $reverseCharge): LineInput
    {
        $field = static fn (string $name): string => \sprintf('lines[%d].%s', $index, $name);
        foreach (array_keys($row) as $key) {
            if (!\in_array($key, self::LINE_KEYS, true) && !\in_array($key, self::IGNORED_KEYS, true)) {
                throw InvoicingRefused::unreadable(InvoicingRefused::LINE, $field((string) $key), \sprintf('A line takes only %s.', implode(', ', self::LINE_KEYS)));
            }
        }
        $text = static function (string $name) use ($row, $field): ?string {
            $value = $row[$name] ?? null;
            if (null !== $value && !\is_string($value)) {
                throw InvoicingRefused::unreadable(InvoicingRefused::LINE, $field($name), 'Every field of a line is a string, numbers included, such as "1500.00".');
            }

            return null === $value ? null : trim($value);
        };
        $price = $text('unit_price') ?? throw InvoicingRefused::unreadable(InvoicingRefused::LINE, $field('unit_price'), 'Every line needs a unit_price, such as "1500.00".');
        if (1 !== preg_match('/^-?\d{1,13}(\.\d{1,'.$currency->minorUnits().'})?$/D', $price)) {
            throw InvoicingRefused::unreadable(InvoicingRefused::NUMBER, $field('unit_price'), \sprintf('The price must be a decimal with at most %d places, such as "1500.00".', $currency->minorUnits()));
        }
        $quantity = $text('quantity') ?? '1';
        if (1 !== preg_match(self::QUANTITY, $quantity)) {
            throw InvoicingRefused::unreadable(InvoicingRefused::NUMBER, $field('quantity'), 'The quantity must be a decimal with at most 4 places, such as "2" or "1.5".');
        }
        $discount = $text('discount') ?? '0';
        try {
            $percent = 1 === preg_match(self::DISCOUNT, $discount) ? Percent::fromString($discount) : null;
        } catch (\InvalidArgumentException) {
            $percent = null;
        }
        if (!$percent instanceof Percent) {
            throw InvoicingRefused::unreadable(InvoicingRefused::NUMBER, $field('discount'), 'The discount is a percentage from 0 to 100 with at most 2 places, such as "10".');
        }
        $priceType = PriceType::tryFrom($text('price_type') ?? PriceType::WithoutVat->value)
            ?? throw InvoicingRefused::unreadable(InvoicingRefused::LINE, $field('price_type'), 'The price type is without_vat, with_vat or base_only.');
        $vat = $text('vat') ?? self::defaultVat($regime, $reverseCharge);
        if (!\in_array($vat, self::VAT, true)) {
            throw InvoicingRefused::unreadable(InvoicingRefused::LINE, $field('vat'), \sprintf('The VAT of a line is one of %s.', implode(', ', self::VAT)));
        }
        [$treatment, $rateKind] = self::vat($vat);
        $supplyCode = $text('supply_code');

        return new LineInput(
            $text('text') ?? '',
            Quantity::fromString($quantity),
            $text('unit') ?? '',
            Money::of($price, $currency),
            $priceType,
            $treatment,
            $rateKind,
            '' === $supplyCode ? null : $supplyCode,
            $percent,
        );
    }

    /**
     * A new line of the form: the base rate for a VAT payer, reverse charge for
     * a customer who is taxed instead, and no VAT for a firm that is no payer.
     */
    private static function defaultVat(VatRegime $regime, bool $reverseCharge): string
    {
        if (VatRegime::Payer !== $regime) {
            return 'none';
        }

        return $reverseCharge ? 'reverse_charge' : 'base';
    }

    /**
     * @return array{TaxTreatment, ?VatRateKind}
     */
    private static function vat(string $choice): array
    {
        return match ($choice) {
            'reduced' => [TaxTreatment::Standard, VatRateKind::Reduced],
            'second_reduced' => [TaxTreatment::Standard, VatRateKind::SecondReduced],
            'reverse_charge' => [TaxTreatment::ReverseCharge, null],
            'exempt' => [TaxTreatment::Exempt, null],
            'none' => [TaxTreatment::NotApplicable, null],
            default => [TaxTreatment::Standard, VatRateKind::Base],
        };
    }

    private static function vatName(TaxTreatment $treatment, ?VatRateKind $rateKind): string
    {
        return match ($treatment) {
            TaxTreatment::ReverseCharge => 'reverse_charge',
            TaxTreatment::Exempt => 'exempt',
            TaxTreatment::NotApplicable => 'none',
            default => match ($rateKind) {
                VatRateKind::Reduced => 'reduced',
                VatRateKind::SecondReduced => 'second_reduced',
                default => 'base',
            },
        };
    }
}
