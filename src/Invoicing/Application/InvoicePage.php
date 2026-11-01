<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * One page of the invoice list.
 */
final readonly class InvoicePage
{
    /**
     * @param list<InvoiceRow> $rows
     */
    public function __construct(public array $rows, public int $total, public int $page, public int $pages)
    {
    }
}
