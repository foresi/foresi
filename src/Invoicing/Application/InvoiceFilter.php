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
    /** A tax document a final invoice deducts. */
    public const string SETTLED = 'settled';
    /** What a final invoice may still deduct: a paid advance, or a tax document of a payment not cancelled. */
    public const string UNSETTLED = 'unsettled';
    public const string TRASH = 'trash';
    /** The tabs of an invoice; tabsOf() gives those of each kind. */
    public const array TABS = [self::ALL, self::PAID, self::UNPAID, self::OVERDUE, self::TRASH];
    public const array SORTS = ['number', 'customer', 'issueDate', 'dueDate', 'payable'];
    public const string DEFAULT_SORT = '-issueDate';

    public function __construct(public ?string $firmId, public string $tab, public string $text, public string $sort, public int $page, public bool $toSend = false, public DocumentKind $kind = DocumentKind::Invoice)
    {
    }

    /**
     * The tabs of the list of a kind, as iDoklad has them: an advance is paid and
     * then settled on a final invoice; a tax document is paid by its payment, so
     * only its settlement tells it apart.
     *
     * @return list<string>
     */
    public static function tabsOf(DocumentKind $kind): array
    {
        return match ($kind) {
            DocumentKind::Invoice => self::TABS,
            DocumentKind::Advance => [self::ALL, self::PAID, self::UNPAID, self::OVERDUE, self::UNSETTLED, self::TRASH],
            DocumentKind::PaymentTaxDocument => [self::ALL, self::SETTLED, self::UNSETTLED, self::TRASH],
        };
    }
}
