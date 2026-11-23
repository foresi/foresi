<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Contract;

use Foresi\Shared\Domain\Money;

/**
 * The VAT of one planned occurrence in CZK: output for money the firm plans
 * to receive, input for money it plans to pay, the other one zero; both the
 * same for a reverse charge the firm self-assesses.
 */
final readonly class PlannedVatAmount
{
    public function __construct(
        /** The key of the source, such as "cash-flow.planned_vat". */
        public string $source,
        /** Null when the caller may not read the plans; the amount still counts. */
        public ?VatSubject $subject,
        /** What a person knows the plan by, such as the planned item's name; null like the subject. */
        public ?string $label,
        public \DateTimeImmutable $on,
        public Money $output,
        public Money $input,
    ) {
    }
}
