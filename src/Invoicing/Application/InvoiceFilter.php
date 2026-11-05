<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;

/**
 * Which documents a list shows: of one kind, of one firm or all, in which tab, matching what text,
 * in what order, on what page. A sort is a name of SORTS, with a minus for descending.
 * To send narrows any tab to the invoices a recurring template issued that were
 * never sent: held for review, or refused for sending.
 */
final readonly class InvoiceFilter
{
    public const string ALL = 'all';
    /** Paid and Overpaid. */
    public const string PAID = 'paid';
    /** Unpaid and PartlyPaid, out of the trash. */
    public const string UNPAID = 'unpaid';
    public const string OVERDUE = 'overdue';
    public const string TRASH = 'trash';
    public const array TABS = [self::ALL, self::PAID, self::UNPAID, self::OVERDUE, self::TRASH];
    public const array SORTS = ['number', 'customer', 'issueDate', 'dueDate', 'payable'];
    public const string DEFAULT_SORT = '-issueDate';

    public function __construct(public ?string $firmId, public string $tab, public string $text, public string $sort, public int $page, public bool $toSend = false, public DocumentKind $kind = DocumentKind::Invoice)
    {
    }
}
