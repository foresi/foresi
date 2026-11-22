<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Contract;

/**
 * How a country suggests paying the VAT to its tax office: a hint of the
 * account, which the person completes with their own office's number, the
 * variable symbol or reference, and the public page it comes from.
 */
final readonly class PaymentSuggestion
{
    public function __construct(
        public string $accountHint,
        public string $variableSymbol,
        public string $sourceUrl,
    ) {
    }
}
