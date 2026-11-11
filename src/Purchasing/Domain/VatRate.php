<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Domain;

/**
 * A VAT rate as the document prints it, in percent with at most two decimals:
 * 21, 12, 0. A received document keeps the rate itself rather than a kind of
 * rate, as a supplier abroad or a past year may use any rate.
 */
final readonly class VatRate
{
    private const string FORMAT = '/^\d{1,3}(\.\d{1,2})?$/D';

    private function __construct(private string $percent)
    {
    }

    /**
     * @throws ReceivedDocumentRefused for anything but a percentage from 0 to 100
     */
    public static function of(string $percent): self
    {
        $percent = trim($percent);
        if (1 !== preg_match(self::FORMAT, $percent)) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::VAT_RATE);
        }
        [$whole, $fraction] = explode('.', $percent.'.');
        $whole = ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');
        $whole = '' === $whole ? '0' : $whole;
        if ($whole > 100 || ('100' === $whole && '' !== $fraction)) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::VAT_RATE);
        }

        return new self('' === $fraction ? $whole : $whole.'.'.$fraction);
    }

    public function percent(): string
    {
        return $this->percent;
    }

    public function isZero(): bool
    {
        return '0' === $this->percent;
    }

    /**
     * The rate as a factor for Money: 21 is 0.21, 10.5 is 0.105.
     */
    public function factor(): string
    {
        [$whole, $fraction] = explode('.', $this->percent.'.');
        $digits = str_pad($whole, 3, '0', \STR_PAD_LEFT).str_pad($fraction, 2, '0');

        return substr($digits, 0, 1).'.'.substr($digits, 1);
    }
}
