<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Shared\Application\Api\Field;
use Foresi\Shared\Application\Api\Operation;

#[Operation(
    name: 'mail.addresses.create',
    method: 'POST',
    path: '/api/v1/mail/addresses',
    tool: 'foresi_create_mail_address',
    scope: 'mail_addresses:write',
    title: 'Create an inbound mail address',
    description: 'Creates a secret e-mail address of your own for one purpose, so that mail sent to it reaches the module that takes that purpose. You have one address per purpose; replace it with foresi_replace_mail_address. The answer holds the full address once, as address; Foresi keeps only its hash, so it cannot be shown again; do not log or store it. Bank feeds are not yours to create: the bank account has its own address.',
    status: 201,
    response: 'MailAddressCreated',
    staffOnly: true,
)]
final readonly class CreateAddressInput
{
    public function __construct(
        #[Field('What the address is for, as the key a module registered, such as "purchasing".')]
        public string $purpose,
        #[Field('What you call it, such as "Receipts to the accountant": 1 to 80 characters.')]
        public string $label,
    ) {
    }
}
