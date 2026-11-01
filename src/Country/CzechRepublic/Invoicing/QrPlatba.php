<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Country\CzechRepublic\Invoicing;

use App\Invoicing\Application\IssuedDocument;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\RateSummary;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * The QR Platba+F text of a document: a payment by the ČBA standard (SPD), with the
 * invoice by the QR Faktura standard (SID) in its X-INV key, encoded as one value.
 *
 * Strict fields are never cut: a value too long for its key leaves the key out, and
 * a document that cannot be paid this way, or whose number the mandatory ID cannot
 * carry, has no code. The ČBA text wins over
 * qr-platba.cz: UTF-8, no CRC32.
 */
final readonly class QrPlatba
{
    private const int MAX_NUMBER_LENGTH = 40;
    private const int MAX_AMOUNT_MINOR = 999_999_999;
    private const string SYMBOL = '/^\d{1,10}\z/';
    private const int TAX_DOCUMENT = 9;
    private const int NON_TAX_DOCUMENT = 0;
    private const int NORMAL = 0;
    private const int REVERSE_CHARGE = 1;
    private const int MIXED = 2;

    public function __construct(private CzechVatRates $rates)
    {
    }

    /**
     * Whether a document number fits the mandatory ID of the invoice part.
     */
    public static function canCarry(string $number): bool
    {
        return mb_strlen($number) <= self::MAX_NUMBER_LENGTH && 1 !== preg_match('/[*%]/', $number);
    }

    public function for(IssuedDocument $document): ?string
    {
        $iban = $document->bankAccount ?? '';
        $payable = $document->payable->minor();
        if (!self::canCarry($document->number) || PaymentMethod::BankTransfer !== $document->paymentMethod || '' === $iban || Currency::CZK !== $document->currency || $payable <= 0 || $payable > self::MAX_AMOUNT_MINOR) {
            return null;
        }

        $pairs = [
            $this->pair('ACC', $iban, 46),
            $this->pair('AM', $this->amount($document->payable), 10),
            $this->pair('CC', $document->currency->value, 3),
            $this->pair('DT', $document->dueDate->format('Ymd'), 8),
            $this->pair('MSG', $document->number, 60),
            $this->symbol('X-VS', $document->variableSymbol),
            $this->symbol('X-KS', $document->constantSymbol),
            // Inside SID a value holds no star and no percent sign, so only its separators are encoded.
            'X-INV:'.str_replace('*', '%2A', $this->invoice($document)),
        ];

        return 'SPD*1.0*'.implode('*', array_filter($pairs, static fn (string $pair): bool => '' !== $pair));
    }

    private function invoice(IssuedDocument $document): string
    {
        $payer = VatRegime::Payer === $document->regime;
        $pairs = [
            $this->sidPair('ID', $document->number, self::MAX_NUMBER_LENGTH),
            $this->sidPair('DD', $document->issueDate->format('Ymd'), 8),
            $this->sidPair('TP', (string) $this->supplyType($document), 1),
            $this->sidPair('TD', (string) ($payer ? self::TAX_DOCUMENT : self::NON_TAX_DOCUMENT), 1),
            $this->sidPair('ON', $document->orderNumber, 20),
            $payer ? $this->sidPair('VII', $document->issuer->vatId ?? '', 14) : '',
            $this->sidPair('INI', $document->issuer->registrationNumber ?? '', 8),
            $payer ? $this->sidPair('VIR', $document->customer->vatId ?? '', 14) : '',
            $this->sidPair('INR', $document->customer->registrationNumber ?? '', 8),
        ];
        if ($payer) {
            $pairs = [...$pairs, $this->sidPair('DUZP', $document->taxPointDate->format('Ymd'), 8), ...$this->taxes($document)];
        }

        return 'SID*1.0*'.implode('*', array_filter($pairs, static fn (string $pair): bool => '' !== $pair));
    }

    private function supplyType(IssuedDocument $document): int
    {
        $reverse = array_any($document->summaries, static fn (RateSummary $summary): bool => TaxTreatment::ReverseCharge === $summary->treatment);
        $standard = array_any($document->summaries, static fn (RateSummary $summary): bool => TaxTreatment::Standard === $summary->treatment);

        if ($reverse) {
            return $standard ? self::MIXED : self::REVERSE_CHARGE;
        }

        return self::NORMAL;
    }

    /**
     * @return list<string>
     */
    private function taxes(IssuedDocument $document): array
    {
        $base = [];
        $reduced = [];
        $free = null;
        foreach ($document->summaries as $summary) {
            if (!$summary->treatment->hasRate()) {
                $free = $free?->plus($summary->base) ?? $summary->base;
            } elseif ($this->isBaseRate($summary->rate, $document->taxPointDate)) {
                $base = [$this->sidPair('TB0', $this->amount($summary->base), 18), $this->sidPair('T0', $this->amount($summary->vat), 18)];
            } else {
                $reduced = [$this->sidPair('TB1', $this->amount($summary->base), 18), $this->sidPair('T1', $this->amount($summary->vat), 18)];
            }
        }

        return [...$base, ...$reduced, null === $free ? '' : $this->sidPair('NTB', $this->amount($free), 18)];
    }

    private function isBaseRate(?Percent $rate, \DateTimeImmutable $taxPoint): bool
    {
        return $rate instanceof Percent && $this->rates->rate(VatRateKind::Base, $taxPoint)->equals($rate);
    }

    private function symbol(string $key, string $value): string
    {
        return 1 === preg_match(self::SYMBOL, $value) ? $key.':'.$value : '';
    }

    /**
     * The QR Faktura standard forbids a star in a value and has no escape for it, and
     * ID is mandatory there, so such a key is left out.
     */
    private function sidPair(string $key, string $value, int $limit): string
    {
        return 1 === preg_match('/[*%]/', $value) || mb_strlen($value) > $limit || '' === $value ? '' : $key.':'.$value;
    }

    private function pair(string $key, string $value, int $limit): string
    {
        return '' === $value || mb_strlen($value) > $limit ? '' : $key.':'.$this->escaped($value);
    }

    /**
     * Only what would end a value or look like encoding; a colon may stay.
     */
    private function escaped(string $value): string
    {
        return str_replace(['%', '*'], ['%25', '%2A'], $value);
    }

    private function amount(Money $money): string
    {
        $minor = $money->minor();

        return \sprintf('%s%d.%02d', $minor < 0 ? '-' : '', intdiv(abs($minor), 100), abs($minor) % 100);
    }
}
