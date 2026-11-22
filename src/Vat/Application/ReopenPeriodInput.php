<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * The mark that a period is settled removed.
 */
#[Operation(
    name: 'vat.period.reopen',
    method: 'DELETE',
    path: '/api/v1/vat/periods/{key}/settle',
    tool: 'foresi_reopen_vat_period',
    scope: 'vat:write',
    title: 'Reopen a VAT period',
    description: 'Removes the mark that a VAT period is settled, so its flow comes back into the cash-flow forecast. A period a bank payment pays stays settled: assign that payment elsewhere with foresi_assign_vat_payment. A period without the mark is refused with not_found.',
    response: 'VatPeriodDetail',
    staffOnly: true,
)]
final readonly class ReopenPeriodInput
{
    public function __construct(
        #[Field('The period, a month such as "2026-11" or a quarter such as "2026-Q4".', maxLength: 7)]
        public string $key,
    ) {
    }
}
