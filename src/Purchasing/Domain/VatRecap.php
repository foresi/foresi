<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Domain;

use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * The VAT of a received document per rate, one row per rate, with its amounts in
 * CZK (ADR 0028). The VAT in CZK follows the treatment:
 *
 * - self-assessed: the base in CZK times the rate, as the firm computes the tax;
 * - charged by the supplier: the CZK amount the document states, else the VAT
 *   converted at the exchange rate of the tax point (a CZK document states it);
 * - no VAT document: zero.
 *
 * Kept as one JSON value on the document, so the document checks and audits its
 * recap as a whole.
 */
final readonly class VatRecap
{
    public const int MAX_ROWS = 8;

    /**
     * @param list<VatRecapRow> $rows
     */
    private function __construct(private Currency $currency, private array $rows)
    {
    }

    public static function empty(Currency $currency): self
    {
        return new self($currency, []);
    }

    /**
     * @param list<VatRecapLine> $lines
     *
     * @throws ReceivedDocumentRefused when a line does not fit the document
     */
    public static function of(array $lines, Currency $currency, ?ExchangeRate $rate, PurchaseTaxTreatment $treatment): self
    {
        if (\count($lines) > self::MAX_ROWS) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::VAT_RECAP_TOO_LONG);
        }
        $rows = [];
        $seen = [];
        foreach ($lines as $line) {
            self::refuseUnfit($line, $currency, $treatment);
            $percent = $line->rate->percent();
            if (isset($seen[$percent])) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::VAT_RECAP_DUPLICATE_RATE);
            }
            $seen[$percent] = true;
            $baseCzk = $rate?->toCzk($line->base);
            $rows[] = new VatRecapRow($line->rate, $line->base, $line->vat, $line->statedVatCzk, $baseCzk, self::vatCzkOf($line, $baseCzk, $rate, $treatment));
        }

        return new self($currency, $rows);
    }

    /**
     * @param list<array{rate: string, base: int, vat: int, stated_vat_czk: ?int, base_czk: ?int, vat_czk: ?int}> $stored
     */
    public static function fromArray(array $stored, Currency $currency): self
    {
        $czk = static fn (?int $minor): ?Money => null === $minor ? null : Money::ofMinor($minor, Currency::CZK);
        $rows = array_map(static fn (array $row): VatRecapRow => new VatRecapRow(
            VatRate::of($row['rate']),
            Money::ofMinor($row['base'], $currency),
            Money::ofMinor($row['vat'], $currency),
            $czk($row['stated_vat_czk']),
            $czk($row['base_czk']),
            $czk($row['vat_czk']),
        ), $stored);

        return new self($currency, $rows);
    }

    /**
     * @return list<array{rate: string, base: int, vat: int, stated_vat_czk: ?int, base_czk: ?int, vat_czk: ?int}>
     */
    public function toArray(): array
    {
        return array_map(static fn (VatRecapRow $row): array => [
            'rate' => $row->rate->percent(),
            'base' => $row->base->minor(),
            'vat' => $row->vat->minor(),
            'stated_vat_czk' => $row->statedVatCzk?->minor(),
            'base_czk' => $row->baseCzk?->minor(),
            'vat_czk' => $row->vatCzk?->minor(),
        ], $this->rows);
    }

    /**
     * @return list<VatRecapRow>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    public function isEmpty(): bool
    {
        return [] === $this->rows;
    }

    public function base(): Money
    {
        return array_reduce($this->rows, static fn (Money $sum, VatRecapRow $row): Money => $sum->plus($row->base), Money::ofMinor(0, $this->currency));
    }

    public function vat(): Money
    {
        return array_reduce($this->rows, static fn (Money $sum, VatRecapRow $row): Money => $sum->plus($row->vat), Money::ofMinor(0, $this->currency));
    }

    /**
     * Null while some row has no base in CZK.
     */
    public function baseCzk(): ?Money
    {
        return $this->sumCzk(array_map(static fn (VatRecapRow $row): ?Money => $row->baseCzk, $this->rows));
    }

    /**
     * Null while some row has no VAT in CZK.
     */
    public function vatCzk(): ?Money
    {
        return $this->sumCzk(array_map(static fn (VatRecapRow $row): ?Money => $row->vatCzk, $this->rows));
    }

    private static function refuseUnfit(VatRecapLine $line, Currency $currency, PurchaseTaxTreatment $treatment): void
    {
        if ($line->base->currency() !== $currency || $line->vat->currency() !== $currency) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::VAT_RECAP_CURRENCY);
        }
        if ($line->statedVatCzk instanceof Money) {
            if (Currency::CZK !== $line->statedVatCzk->currency()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::VAT_RECAP_CURRENCY);
            }
            // A CZK document states its VAT in CZK already, and self-assessed VAT is the firm's own.
            if (Currency::CZK === $currency || !$treatment->takesStatedVat()) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::STATED_VAT_CZK);
            }
        }
        if (PurchaseTaxTreatment::NotVatDocument === $treatment && (!$line->vat->isZero() || !$line->rate->isZero())) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::VAT_ON_NON_VAT_DOCUMENT);
        }
    }

    private static function vatCzkOf(VatRecapLine $line, ?Money $baseCzk, ?ExchangeRate $rate, PurchaseTaxTreatment $treatment): ?Money
    {
        if (PurchaseTaxTreatment::NotVatDocument === $treatment) {
            return Money::ofMinor(0, Currency::CZK);
        }
        if ($treatment->isSelfAssessed()) {
            return $baseCzk?->multipliedBy($line->rate->factor());
        }

        return $line->statedVatCzk ?? $rate?->toCzk($line->vat);
    }

    /**
     * @param list<?Money> $amounts
     */
    private function sumCzk(array $amounts): ?Money
    {
        $sum = Money::ofMinor(0, Currency::CZK);
        foreach ($amounts as $amount) {
            if (!$amount instanceof Money) {
                return null;
            }
            $sum = $sum->plus($amount);
        }

        return $sum;
    }
}
