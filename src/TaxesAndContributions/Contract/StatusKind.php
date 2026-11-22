<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * A status of the owner that changes on a day and holds until the next change
 * of its kind: the legal form, the activity, VAT registration, the flat-rate
 * band, voluntary sickness insurance and an exemption from the health minimum.
 * VAT registration is the firm's: it comes from its VAT statuses in Party,
 * and only the other kinds are written here.
 */
enum StatusKind: string
{
    case LegalForm = 'legal_form';
    case Activity = 'activity';
    case VatPayer = 'vat_payer';
    case FlatRateBand = 'flat_rate_band';
    case SicknessInsurance = 'sickness_insurance';
    case HealthMinimumExempt = 'health_minimum_exempt';

    /**
     * The kinds the owner profile stores and writes: all but VatPayer, which
     * is read from the firm's VAT statuses.
     *
     * @return non-empty-list<self>
     */
    public static function writable(): array
    {
        return [self::LegalForm, self::Activity, self::FlatRateBand, self::SicknessInsurance, self::HealthMinimumExempt];
    }

    /**
     * The values a status of this kind takes.
     *
     * @return non-empty-list<string>
     */
    public function values(): array
    {
        return match ($this) {
            self::LegalForm => ['sole_trader'],
            self::Activity => ['none', 'secondary', 'main'],
            self::VatPayer, self::HealthMinimumExempt => ['yes', 'no'],
            self::FlatRateBand => ['none', '1', '2', '3'],
            self::SicknessInsurance => ['off', 'on'],
        };
    }

    /**
     * Whether a status of this value carries a monthly base in CZK: voluntary
     * sickness insurance that is on, and nothing else.
     */
    public function needsBase(string $value): bool
    {
        return self::SicknessInsurance === $this && 'on' === $value;
    }
}
