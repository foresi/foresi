<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Domain;

/**
 * When the VAT of a received document can be deducted (ADR 0028), for a firm
 * that files monthly. The right arises on the tax point. VAT charged by the
 * supplier is deducted at the earliest in the month the firm has both the
 * supply and the document, so the later of the tax point and the received date,
 * and at the latest by the end of the second calendar year after the right
 * arose. Self-assessed VAT is deducted in the month it is declared, the month of
 * the tax point, and has no deadline of its own as the firm declares the tax in
 * the same return. Non-deductible VAT and documents without VAT have neither.
 */
final readonly class DeductionDates
{
    private function __construct(
        /** The first day of the first month the VAT can be deducted in. */
        public ?\DateTimeImmutable $periodStart,
        /** The last day the deduction can be claimed. */
        public ?\DateTimeImmutable $latestDate,
    ) {
    }

    public static function of(PurchaseTaxTreatment $treatment, ?\DateTimeImmutable $taxPoint, ?\DateTimeImmutable $received): self
    {
        if (!$treatment->isDeductible() || !$taxPoint instanceof \DateTimeImmutable) {
            return new self(null, null);
        }
        if ($treatment->isSelfAssessed()) {
            return new self(self::monthOf($taxPoint), null);
        }
        if (!$received instanceof \DateTimeImmutable) {
            return new self(null, null);
        }

        return new self(self::monthOf(max($received, $taxPoint)), new \DateTimeImmutable($taxPoint->modify('+2 years')->format('Y-12-31')));
    }

    private static function monthOf(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-01'));
    }
}
