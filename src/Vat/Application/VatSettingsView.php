<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Vat\Contract\PaymentSuggestion;

/**
 * Where the firm pays its VAT, null until it is set, and how its country
 * suggests paying it, null without the country's rules.
 */
final readonly class VatSettingsView
{
    public function __construct(
        public ?string $officeAccount,
        public ?string $variableSymbol,
        public ?PaymentSuggestion $suggestion,
    ) {
    }
}
