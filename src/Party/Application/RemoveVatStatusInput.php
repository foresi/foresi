<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A VAT status of the firm removed, so the one before it holds again.
 */
#[Operation(
    name: 'party.firm.vat_status.remove',
    method: 'DELETE',
    path: '/api/v1/firm/vat-statuses/{vatStatusId}',
    tool: 'foresi_remove_firm_vat_status',
    scope: 'firm:write',
    title: 'Remove a VAT status of the firm',
    description: 'Removes a VAT status of the firm, found in vat_statuses of foresi_get_firm; the one before it then holds until the next one. The last status is never removed: add the new one first. Only the owner of the organisation may do this.',
    destructive: true,
    response: 'FirmVatStatusRemoved',
    staffOnly: true,
)]
final readonly class RemoveVatStatusInput
{
    public function __construct(
        #[Field('The id of the VAT status.', maxLength: 36)]
        public string $vatStatusId,
    ) {
    }
}
