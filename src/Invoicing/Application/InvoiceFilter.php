<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\ExportState;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * Which documents a list shows: of one kind, of one firm or all, in which tab, matching what text,
 * in what order, on what page. A sort is a name of SORTS, with a minus for descending.
 * To send narrows any tab to the invoices a recurring template issued that were
 * never sent: held for review, or refused for sending. An export state narrows any
 * tab to the documents in it; null takes them all. A month, as YYYY-MM, narrows it to the
 * documents of that month: a tax document by its tax point, an advance by its issue date.
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
    /**
     * Invoices paid above what is due, after their corrections or without any, or whose
     * deducted advances exceed them; of corrective documents, those whose invoice is so.
     */
    public const string TO_REFUND = 'to_refund';
    public const string TRASH = 'trash';
    /** The tabs of an invoice; tabsOf() gives those of each kind. */
    public const array TABS = [self::ALL, self::PAID, self::UNPAID, self::OVERDUE, self::TO_REFUND, self::TRASH];
    public const array SORTS = ['number', 'customer', 'issueDate', 'dueDate', 'payable'];
    public const string DEFAULT_SORT = '-issueDate';

    /** The periods the month chip offers before the months with documents (spec D5). */
    public const array RELATIVE_MONTHS = ['this_month', 'last_month', 'this_quarter', 'this_year'];

    /**
     * The view is what the list keeps: its conditions, checked against the kind's list
     * (InvoiceLists), or those of the tab's default view when it is null. Counted are
     * the views whose documents a page counts, by key; none counts the kind's tabs.
     *
     * @param array<string, ViewFilter> $counted
     */
    public function __construct(public ?string $firmId, public string $tab, public string $text, public string $sort, public int $page, public bool $toSend = false, public DocumentKind $kind = DocumentKind::Invoice, public ?ExportState $export = null, public ?string $month = null, public ?ViewFilter $view = null, public array $counted = [])
    {
    }

    /**
     * The month a query names: YYYY-MM, or a period relative to today such as
     * last_month, which the query resolves on the day it runs; anything else names none.
     */
    public static function monthOf(string $value): ?string
    {
        return 1 === preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/', $value) || \in_array($value, self::RELATIVE_MONTHS, true) ? $value : null;
    }

    /**
     * The month a query names as a date value of a filter (spec D5).
     *
     * @return array{month: string}|array{relative: string}|null
     */
    public static function monthValue(?string $month): ?array
    {
        return match (true) {
            null === $month => null,
            \in_array($month, self::RELATIVE_MONTHS, true) => ['relative' => $month],
            default => ['month' => $month],
        };
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
            DocumentKind::CorrectionDocument => [self::ALL, self::TO_REFUND, self::TRASH],
        };
    }
}
