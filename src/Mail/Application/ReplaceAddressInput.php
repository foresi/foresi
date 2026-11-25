<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'mail.addresses.replace',
    method: 'POST',
    path: '/api/v1/mail/addresses/{addressId}/replace',
    tool: 'foresi_replace_mail_address',
    scope: 'mail_addresses:write',
    title: 'Replace an inbound mail address',
    description: 'Revokes one of your inbound addresses and makes a new one for the same purpose with the same label, in one step: use it when an address leaked or spam reaches it. The old address takes in nothing from now on. The answer holds the new address once, as address; it cannot be shown again; do not log or store it. The answer is the new address, with a new id.',
    status: 201,
    response: 'MailAddressCreated',
    staffOnly: true,
)]
final readonly class ReplaceAddressInput
{
    public function __construct(
        #[Field('The id of the address to replace.', format: 'uuid')]
        public string $addressId,
    ) {
    }
}
