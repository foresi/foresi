<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Domain;

use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * CZK for one unit of the document's currency, as a decimal text; never a float.
 * Stored as given, without trailing zeros, so 1.000 and 1 are the same rate.
 * Where the rate came from (a published fix, a bank, a person) is not its
 * concern.
 */
final readonly class ExchangeRate
{
    public const int MAX_LENGTH = 32;
    private const string FORMAT = '/^\d{1,9}(\.\d{1,9})?$/D';

    private function __construct(private string $value)
    {
    }

    /**
     * @throws ReceivedDocumentRefused for anything but a positive decimal
     */
    public static function of(string $value): self
    {
        $value = trim($value);
        if (1 !== preg_match(self::FORMAT, $value)) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::EXCHANGE_RATE);
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        $value = ltrim($value, '0');
        if ('' === $value || str_starts_with($value, '.')) {
            $value = '0'.$value;
        }
        if ('0' === $value) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::EXCHANGE_RATE);
        }

        return new self($value);
    }

    public static function one(): self
    {
        return new self('1');
    }

    public function isOne(): bool
    {
        return '1' === $this->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    /**
     * The amount in CZK, rounded half up to the minor unit.
     */
    public function toCzk(Money $amount): Money
    {
        if (Currency::CZK === $amount->currency()) {
            return $amount;
        }

        return Money::ofMinor($amount->multipliedBy($this->value)->minor(), Currency::CZK);
    }
}
