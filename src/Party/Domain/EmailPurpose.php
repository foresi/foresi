<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

enum EmailPurpose: string
{
    case General = 'general';
    case Invoices = 'invoices';
    case Copy = 'copy';
}
