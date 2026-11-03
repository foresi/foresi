<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * Who a link to a sent document was made for. The firm's own copy has a link of
 * its own, so opening it never reads as the customer opening theirs.
 */
enum LinkAudience: string
{
    case Customer = 'customer';
    case Copy = 'copy';
}
