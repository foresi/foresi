<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

/**
 * A status removed from the owner profile, so the one before it holds again.
 */
#[Operation(
    name: 'taxes.profile.status.remove',
    method: 'DELETE',
    path: '/api/v1/taxes-and-contributions/profile/statuses/{statusId}',
    tool: 'foresi_remove_tax_status',
    scope: 'taxes:write',
    title: 'Remove a tax status',
    description: 'Removes a status of the owner profile, found in statuses of foresi_get_tax_profile; the status of its kind before it then holds until the next one. Only the owner of the organisation may do this.',
    destructive: true,
    response: 'TaxStatusRemoved',
    staffOnly: true,
)]
final readonly class RemoveStatusInput
{
    public function __construct(
        #[Field('The id of the status.', maxLength: 36)]
        public string $statusId,
    ) {
    }
}
