<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application\Query;

use App\Party\Domain\EmailPurpose;

final readonly class ContactEmail
{
    public function __construct(public string $id, public string $address, public EmailPurpose $purpose)
    {
    }
}
