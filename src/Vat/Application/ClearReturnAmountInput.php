<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * The return amount of a period removed, so the estimate counts again.
 */
#[Operation(
    name: 'vat.period.return_amount.clear',
    method: 'DELETE',
    path: '/api/v1/vat/periods/{key}/return-amount',
    tool: 'foresi_clear_vat_return_amount',
    scope: 'vat:write',
    title: 'Clear the VAT return amount',
    description: 'Removes the return amount entered for a period, so the estimate from the documents is what the period expects again. A period without one is refused with not_found.',
    response: 'VatPeriodDetail',
    staffOnly: true,
)]
final readonly class ClearReturnAmountInput
{
    public function __construct(
        #[Field('The period, a month such as "2026-11" or a quarter such as "2026-Q4".', maxLength: 7)]
        public string $key,
    ) {
    }
}
