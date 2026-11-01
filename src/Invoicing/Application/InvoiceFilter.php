<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Which invoices a list shows: of one firm or all, in the trash or not, on what page.
 */
final readonly class InvoiceFilter
{
    public function __construct(public ?string $firmId, public bool $trashed, public int $page)
    {
    }
}
