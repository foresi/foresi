<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * A VAT status of the firm from a day on, such as a payer by the month from
 * 2026-11-12, until the next one begins.
 */
#[Operation(
    name: 'party.firm.vat_status.add',
    method: 'POST',
    path: '/api/v1/firm/vat-statuses',
    tool: 'foresi_add_firm_vat_status',
    scope: 'contacts:write',
    title: 'Add a VAT status of the firm',
    description: 'Adds a VAT status of the firm that holds from valid_from until the next one begins, such as {"status": "registered", "period_length": "month", "valid_from": "2026-11-12"}. '
        .'The status is registered (a VAT payer), not_registered or identified_person; a registered payer needs period_length month or quarter, and no other status takes one. '
        .'Two statuses never start on one day: remove the old one first with foresi_remove_firm_vat_status. A status of today that needs a VAT ID needs one on the firm. Only the owner of the organisation may do this.',
    status: 201,
    response: 'FirmVatStatus',
    staffOnly: true,
)]
final readonly class AddVatStatusInput
{
    public function __construct(
        #[Field('The VAT status.', enum: ['registered', 'not_registered', 'identified_person'])]
        public string $status,
        #[Field('The first day it holds, in ISO 8601, such as "2026-11-12".', format: 'date')]
        public string $validFrom,
        #[Field('The length of a VAT period of a registered payer; only for registered.', enum: ['month', 'quarter'])]
        public ?string $periodLength = null,
    ) {
    }
}
