<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'mail.addresses.rename',
    method: 'PATCH',
    path: '/api/v1/mail/addresses/{addressId}',
    tool: 'foresi_rename_mail_address',
    scope: 'mail_addresses:write',
    title: 'Rename an inbound mail address',
    description: 'Changes the label of one of your inbound addresses; the address itself stays the same. Find the id with foresi_list_mail_addresses.',
    response: 'MailAddress',
    staffOnly: true,
)]
final readonly class RenameAddressInput
{
    public function __construct(
        #[Field('The id of the address.', format: 'uuid')]
        public string $addressId,
        #[Field('The new label, 1 to 80 characters.')]
        public string $label,
    ) {
    }
}
