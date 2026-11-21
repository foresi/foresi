<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * One amount the firm expects by the end of the year, in CZK, on the day the
 * forecast counts it. Income is positive and a refund of income negative, and
 * the same for costs. The subject is the document, the schedule or the plan
 * it comes from, or null when the caller may not read its source's records.
 */
final readonly class ForecastAmount
{
    public function __construct(
        public string $source,
        public ?Subject $subject,
        public AmountKind $kind,
        public \DateTimeImmutable $on,
        public Money $grossCzk,
        public ForecastCertainty $certainty,
    ) {
    }
}
