<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A person's placement of a bank movement removed, so the automatic matching
 * decides again.
 */
#[Operation(
    name: 'vat.payment.reset',
    method: 'DELETE',
    path: '/api/v1/vat/payments/{movementId}',
    tool: 'foresi_reset_vat_payment',
    scope: 'vat:write',
    title: 'Match a VAT payment automatically',
    description: 'Removes what foresi_assign_vat_payment decided about a bank movement, so the automatic matching places it again. A movement nobody placed is refused with not_found.',
    response: 'VatPaymentAssignment',
    staffOnly: true,
)]
final readonly class ResetPaymentInput
{
    public function __construct(
        #[Field('The id of the bank movement, as payments and unassigned_payments of foresi_vat_periods name it.', maxLength: 64)]
        public string $movementId,
    ) {
    }
}
