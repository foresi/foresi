<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Whether VAT arises when a payment of an advance invoice is received, so that a
 * tax document on the payment is due, and if not, why not. Where it arises, the
 * deadline of the document runs from the day of receipt, or from the end of its
 * month for a supply whose place is in another country.
 */
final readonly class TaxOnReceipt
{
    public const string NON_PAYER = 'non_payer';
    public const string UNKNOWN_SUPPLY = 'unknown_supply';
    public const string CONSUMER = 'consumer';
    public const string DOMESTIC_REVERSE_CHARGE = 'domestic_reverse_charge';
    /** The days the law gives for the tax document. */
    public const int DAYS = 15;

    private function __construct(public bool $arises, public ?string $reason, public bool $crossBorder)
    {
    }

    public static function arises(bool $crossBorder = false): self
    {
        return new self(true, null, $crossBorder);
    }

    /**
     * @param self::NON_PAYER|self::UNKNOWN_SUPPLY|self::CONSUMER|self::DOMESTIC_REVERSE_CHARGE $reason
     */
    public static function none(string $reason): self
    {
        return new self(false, $reason, false);
    }

    /**
     * The last day for the tax document on a payment received on that day.
     */
    public function deadline(\DateTimeImmutable $receivedOn): \DateTimeImmutable
    {
        $day = new \DateTimeImmutable($receivedOn->format('Y-m-d'));
        $from = $this->crossBorder ? $day->modify('last day of this month') : $day;

        return $from->modify(\sprintf('+%d days', self::DAYS));
    }
}
