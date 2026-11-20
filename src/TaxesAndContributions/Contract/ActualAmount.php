<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * One payment of one document, on the day it was paid (B3): the gross paid,
 * split into net and VAT in the document's ratio, in the currency of the
 * payment. A refund, or a payment of a credit note, is negative and of the
 * same kind as its document. An advance counts when it is paid; its final
 * document then asks only what remains, so nothing counts twice.
 */
final readonly class ActualAmount
{
    public function __construct(
        public string $source,
        public Subject $subject,
        public AmountKind $kind,
        public \DateTimeImmutable $paidOn,
        public Money $gross,
        public Money $net,
        public Money $vat,
        public ?\DateTimeImmutable $taxPointOn,
        public bool $advance,
    ) {
    }
}
