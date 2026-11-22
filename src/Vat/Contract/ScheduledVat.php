<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Contract;

use Foresi\Shared\Domain\Money;

/**
 * The output VAT one occurrence of a schedule will give, such as the next
 * invoice of a recurring template, on the tax point the issued document
 * would get. The amount is in the schedule's own currency: the VAT module
 * converts it to CZK at today's rate.
 */
final readonly class ScheduledVat
{
    public function __construct(
        /** The key of the source, such as "invoicing.recurring_vat". */
        public string $source,
        public VatSubject $subject,
        /** What a person knows the occurrence by, such as the template's name and the day. */
        public string $label,
        public \DateTimeImmutable $taxPointOn,
        public Money $output,
    ) {
    }
}
