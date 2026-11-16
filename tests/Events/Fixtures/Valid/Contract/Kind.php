<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Fixtures\Valid\Contract;

enum Kind: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
}
