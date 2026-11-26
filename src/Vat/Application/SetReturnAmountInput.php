<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * The amount of the VAT return a person filed for a period, which replaces
 * the estimate until the period is paid.
 */
#[Operation(
    name: 'vat.period.return_amount.set',
    method: 'PUT',
    path: '/api/v1/vat/periods/{key}/return-amount',
    tool: 'foresi_set_vat_return_amount',
    scope: 'vat:write',
    title: 'Enter the VAT return amount',
    description: 'Enters the amount of the VAT return filed for a period, in CZK: positive to pay, negative to get back. It replaces the estimate from the documents as what the period expects (expected), in the period and in the cash-flow forecast, until a payment settles it; the estimate stays in result. It replaces an amount entered before.',
    response: 'VatPeriodDetail',
    staffOnly: true,
)]
final readonly class SetReturnAmountInput
{
    public function __construct(
        #[Field('The period, a month such as "2026-11" or a quarter such as "2026-Q4".', maxLength: 7)]
        public string $key,
        #[Field('The amount of the return in CZK, a decimal number such as "1500.00", negative for a refund.', maxLength: 17)]
        public string $amount,
    ) {
    }
}
