<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * The source the firm converts foreign currencies at.
 */
#[Operation(
    name: 'exchange-rate.source.set',
    method: 'PUT',
    path: '/api/v1/exchange-rate/source',
    tool: 'foresi_set_exchange_rate_source',
    scope: 'exchange_rates:write',
    title: 'Set the exchange-rate source',
    description: 'Chooses where the firm\'s exchange rates come from, such as "cnb" (the Czech National Bank) or "ecb" (the European Central Bank reference rates, converted to CZK through the euro). Only a source the rules of the firm\'s country allow can be chosen; GET /api/v1/exchange-rate/source lists them. '
        .'A document keeps the rate it was saved with; only rates taken from now on come from the new source. There is no fallback to another source when the chosen one is down.',
    response: 'ExchangeRateSource',
    staffOnly: true,
)]
final readonly class SetRateSourceInput
{
    public function __construct(
        #[Field('The name of the source, one of "allowed" in GET /api/v1/exchange-rate/source, such as "cnb" or "ecb".', maxLength: 16)]
        public string $source,
    ) {
    }
}
