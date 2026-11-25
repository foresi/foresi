<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * A bank movement to or from the tax office placed by a person: it pays a
 * period, or it is no VAT payment.
 */
#[Operation(
    name: 'vat.payment.assign',
    method: 'PUT',
    path: '/api/v1/vat/payments/{movementId}',
    tool: 'foresi_assign_vat_payment',
    scope: 'vat:write',
    title: 'Assign a VAT payment',
    description: 'Places a bank movement to or from the tax office: period names the VAT period it pays, such as "2026-11", and null says it is no VAT payment. It wins over the automatic matching, which never looks at the amount and leaves a movement unassigned when no period fits (unassigned_payments of foresi_vat_periods). foresi_reset_vat_payment gives it back to the automatic matching.',
    response: 'VatPaymentAssignment',
    staffOnly: true,
)]
final readonly class AssignPaymentInput
{
    public function __construct(
        #[Field('The id of the bank movement, as payments and unassigned_payments of foresi_vat_periods name it.', maxLength: 64)]
        public string $movementId,
        #[Field('The period it pays, such as "2026-11" or "2026-Q4"; null when it is no VAT payment.', maxLength: 7)]
        public ?string $period,
    ) {
    }
}
