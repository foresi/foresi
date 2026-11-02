<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Shared\Domain\Money;

/**
 * What the pair dialog offers for a movement.
 */
final readonly class PairingOptions
{
    /**
     * @param list<PairingChoice>  $suggested
     * @param list<PairingChoice>  $others
     * @param array<string, Money> $prefill         the part of each invoice filled in, by invoice id
     * @param int|null             $settlementLimit how many minor units a difference on one invoice may be written off, or null when the firm settles none
     */
    public function __construct(
        public array $suggested,
        public array $others,
        public array $prefill,
        public ?int $settlementLimit,
    ) {
    }

    /**
     * The choice of the invoice, among all offered, or null.
     */
    public function choice(string $invoiceId): ?PairingChoice
    {
        foreach ([...$this->suggested, ...$this->others] as $choice) {
            if ($choice->candidate->invoiceId === $invoiceId) {
                return $choice;
            }
        }

        return null;
    }
}
