<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application\Query;

final readonly class ContactBankAccount
{
    public function __construct(public string $id, public string $iban, public ?string $bic, public string $label, public bool $primary)
    {
    }
}
