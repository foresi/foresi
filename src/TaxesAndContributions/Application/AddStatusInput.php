<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * A status of the owner from a day on, such as VAT payer from 2026-11-12.
 */
#[Operation(
    name: 'taxes.profile.status.add',
    method: 'POST',
    path: '/api/v1/taxes-and-contributions/profile/statuses',
    tool: 'foresi_add_tax_status',
    scope: 'taxes:write',
    title: 'Add a tax status',
    description: 'Adds a status of the owner that holds from valid_from until the next status of its kind, such as {"kind": "vat_payer", "value": "yes", "valid_from": "2026-11-12"}. '
        .'The values: legal_form sole_trader; activity none, secondary or main; vat_payer and health_minimum_exempt yes or no; flat_rate_band none, 1, 2 or 3; sickness_insurance off or on, where on needs base, the monthly base in CZK. '
        .'Two statuses of one kind never start on one day: remove the old one first with foresi_remove_tax_status. Only the owner of the organisation may do this.',
    status: 201,
    response: 'TaxStatus',
    staffOnly: true,
)]
final readonly class AddStatusInput
{
    public function __construct(
        #[Field('The kind of status.', enum: ['legal_form', 'activity', 'vat_payer', 'flat_rate_band', 'sickness_insurance', 'health_minimum_exempt'])]
        public string $kind,
        #[Field('The value, one the kind takes, such as "yes".', maxLength: 20)]
        public string $value,
        #[Field('The first day it holds, in ISO 8601, such as "2026-11-12".', format: 'date')]
        public string $validFrom,
        #[Field('The monthly base in CZK of sickness insurance that is on, a decimal such as "10000.00"; only for that.')]
        public ?string $base = null,
    ) {
    }
}
