<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Query;

use Foresi\Purchasing\Application\View\ReceivedDocumentLists;
use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * What a list of received documents shows: the conditions of its view (spec D3),
 * narrowed by month and a text in the supplier's name, the supplier's number
 * or the evidence number. The tab says how the list orders its rows and what its
 * empty state says: the drafts newest first, the recorded documents by the day
 * they came, the trash by when they went there. A document belongs to the month
 * of its tax point, or of its issue date while it names none; a document from
 * abroad to the month of its issue date (ADR 0028).
 */
final readonly class ReceivedDocumentFilter
{
    public const string ALL = 'all';
    public const string DRAFTS = ReceivedDocumentLists::DRAFTS;
    public const string RECORDED = ReceivedDocumentLists::RECORDED;
    public const string TRASH = ReceivedDocumentLists::TRASH;
    /** The drafts come first: they wait for someone, as the inbox of Xero bills does. */
    public const array TABS = [self::ALL, self::DRAFTS, self::RECORDED, self::TRASH];
    /** The periods the month chip offers before the months with a document (spec D5). */
    public const array RELATIVE_MONTHS = ['this_month', 'last_month', 'this_quarter', 'this_year'];
    public const int PER_PAGE = 25;
    public const int MAX_TEXT = 100;

    /** The conditions the list shows, those of the tab's own view unless one was given. */
    public ViewFilter $view;

    /**
     * @param string|null               $month   YYYY-MM, or one of RELATIVE_MONTHS
     * @param array<string, ViewFilter> $counted the views whose documents the page counts, by key
     */
    public function __construct(
        public string $tab = self::DRAFTS,
        public ?string $month = null,
        public string $text = '',
        public int $page = 1,
        ?ViewFilter $view = null,
        public array $counted = [],
    ) {
        if (!\in_array($tab, self::TABS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown tab "%s".', $tab));
        }
        $this->view = $view ?? ReceivedDocumentLists::defaultFilter($tab) ?? new ViewFilter();
    }

    /**
     * A month as YYYY-MM or a period relative to today, or none for anything else.
     */
    public static function monthOf(string $value): ?string
    {
        return 1 === preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $value) || \in_array($value, self::RELATIVE_MONTHS, true) ? $value : null;
    }

    /**
     * The month as a date value of a filter (spec D5).
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
     * Whether the document is placed by its issue date: one from abroad, whose
     * tax point the firm sets by the issue date of the supplier's invoice.
     */
    public static function byIssueDate(ReceivedDocumentKind $kind, PurchaseTaxTreatment $treatment): bool
    {
        return ReceivedDocumentKind::ForeignInvoice === $kind || PurchaseTaxTreatment::EuService === $treatment || PurchaseTaxTreatment::NonEuService === $treatment;
    }

    /**
     * The day that places a document in its month.
     */
    public static function monthDay(ReceivedDocumentKind $kind, PurchaseTaxTreatment $treatment, ?\DateTimeImmutable $issueDate, ?\DateTimeImmutable $taxPointDate): ?\DateTimeImmutable
    {
        return self::byIssueDate($kind, $treatment) ? $issueDate : ($taxPointDate ?? $issueDate);
    }
}
