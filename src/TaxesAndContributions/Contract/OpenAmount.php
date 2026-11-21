<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Foresi\Shared\Domain\Money;

/**
 * What one document still asks at the end of a day: an issued invoice not
 * yet received in full (income), or a received document not yet paid in
 * full (cost), after its corrections and the payments up to that day. It is
 * split into net and VAT in the document's ratio, in the document's currency.
 * A credit note, or a document paid above what it asks, is negative. An
 * advance is none: what was received or paid on it counted when paid.
 */
final readonly class OpenAmount
{
    public function __construct(
        public string $source,
        public Subject $subject,
        public AmountKind $kind,
        public Money $gross,
        public Money $net,
        public Money $vat,
    ) {
    }
}
