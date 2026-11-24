<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Contract;

use Foresi\Shared\Domain\Money;

/**
 * A document that earns the firm revenue, as Margin reads it: the kind is
 * the source's own word for it (invoice, payment_tax_document,
 * correction_document), the customer is a contact id, the tax point is a day
 * in the zone the range was asked in, and the net is in CZK whole hellers,
 * negative for a correction that lowers revenue. The url is where a person
 * opens it.
 */
final readonly class RevenueDocument
{
    /**
     * @param list<RevenueLine> $lines
     */
    public function __construct(
        public string $id,
        public string $number,
        public string $kind,
        public string $customerId,
        public \DateTimeImmutable $taxPointOn,
        public Money $netCzk,
        public string $url,
        public array $lines,
    ) {
    }
}
