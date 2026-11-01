<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Which invoices a list shows: of one firm or all, in which tab, matching what text,
 * in what order, on what page. A sort is a name of SORTS, with a minus for descending.
 */
final readonly class InvoiceFilter
{
    public const string ALL = 'all';
    public const string OVERDUE = 'overdue';
    public const string TRASH = 'trash';
    public const array TABS = [self::ALL, self::OVERDUE, self::TRASH];
    public const array SORTS = ['number', 'customer', 'issueDate', 'dueDate', 'payable'];
    public const string DEFAULT_SORT = '-issueDate';

    public function __construct(public ?string $firmId, public string $tab, public string $text, public string $sort, public int $page)
    {
    }
}
