<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Domain;

use Foresi\Shared\Domain\Money;

/**
 * A line of a received document as reports read it, stored or implicit. A
 * stored line is keyed by its id. A document without stored lines has one
 * implicit line per rate of its VAT recap, keyed rate:<rate>, whose text is
 * the value of the document's kind for the reader to name. The base in CZK is
 * null while the document has no exchange rate.
 */
final readonly class LineView
{
    public function __construct(
        public string $key,
        public string $text,
        public Money $base,
        public ?Money $baseCzk,
        public string $vatRate,
        public bool $implicit,
    ) {
    }
}
