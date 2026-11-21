<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

use Foresi\Shared\Domain\Money;

/**
 * One flow of the forecast as ForecastReader gives it: in its own currency and
 * in CZK at today's rate, on the day the forecast counts it.
 */
final readonly class ReadFlow
{
    /**
     * @param string             $source  the key of the source that gave it
     * @param FlowSubject|null   $subject null when the caller may not read the source's documents; the amount still counts
     * @param \DateTimeImmutable $on      the expected day, or the first day of the read for a flow already overdue
     * @param list<FlowFlag>     $flags
     * @param string|null        $label   what a person reads, such as a planned item's name; null like the subject
     */
    public function __construct(
        public string $source,
        public ?FlowSubject $subject,
        public FlowDirection $direction,
        public Money $amount,
        public Money $amountCzk,
        public \DateTimeImmutable $on,
        public FlowCategory $category,
        public FlowCertainty $certainty,
        public array $flags,
        public ?string $label = null,
    ) {
    }
}
