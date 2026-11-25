<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'mail.addresses.revoke',
    method: 'DELETE',
    path: '/api/v1/mail/addresses/{addressId}',
    tool: 'foresi_revoke_mail_address',
    scope: 'mail_addresses:write',
    title: 'Revoke an inbound mail address',
    description: 'Revokes one of your inbound addresses: it takes in nothing from now on and cannot be brought back. The address stays listed as revoked, and the mail it already took in is kept. To keep receiving, use foresi_replace_mail_address instead, which makes a new address in the same step.',
    destructive: true,
    response: 'MailAddress',
    staffOnly: true,
)]
final readonly class RevokeAddressInput
{
    public function __construct(
        #[Field('The id of the address.', format: 'uuid')]
        public string $addressId,
    ) {
    }
}
