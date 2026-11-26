<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

use Foresi\Shared\Domain\Money;

/**
 * An amount a source expects without knowing the day, such as a won deal
 * nobody has given an invoicing day yet. The forecast shows it apart, in its
 * own "No date" column, and never counts it in a balance: any day it chose
 * would be a guess. The amount is positive; the direction says which way it
 * goes. Since module API 1.8.
 */
final readonly class UndatedFlow
{
    /**
     * @param string      $source         the key of the source that gave it
     * @param string      $label          what a person reads, such as a deal's title
     * @param string|null $counterpartyId the contact the money comes from or goes to
     */
    public function __construct(
        public string $source,
        public FlowSubject $subject,
        public FlowDirection $direction,
        public Money $amount,
        public FlowCategory $category,
        public FlowCertainty $certainty,
        public string $label,
        public ?string $counterpartyId = null,
    ) {
        if ($amount->isNegative()) {
            throw new \InvalidArgumentException('An undated flow has a positive amount; its direction says which way it goes.');
        }
    }
}
