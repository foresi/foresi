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
     * @param list<InvoiceRow>      $rows
     * @param array<string, int>    $tabCounts by tab of InvoiceFilter, under the same firm and text
     * @param array<string, string> $firms     name by id of every firm with an invoice, the trash included
     */
    public function __construct(public array $rows, public int $total, public array $tabCounts, public int $page, public int $pages, public array $firms)
    {
    }
}
