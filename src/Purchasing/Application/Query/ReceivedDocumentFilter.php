<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Query;

use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocumentKind;

/**
 * What a list of received documents shows: one tab (the drafts to complete
 * first, the recorded documents, the trash), narrowed by firm, month, supplier,
 * kind, treatment and a text in the supplier's name, the supplier's number or
 * the evidence number. A document belongs to the month of its tax point, or of
 * its issue date while it names none; a document from abroad to the month of
 * its issue date (ADR 0028).
 */
final readonly class ReceivedDocumentFilter
{
    public const string DRAFTS = 'drafts';
    public const string RECORDED = 'recorded';
    public const string TRASH = 'trash';
    /** The drafts come first: they wait for someone, as the inbox of Xero bills does. */
    public const array TABS = [self::DRAFTS, self::RECORDED, self::TRASH];
    public const int PER_PAGE = 25;
    public const int MAX_TEXT = 100;

    public function __construct(
        public string $tab = self::DRAFTS,
        public ?string $firmId = null,
        public ?string $month = null,
        public ?string $supplierId = null,
        public ?ReceivedDocumentKind $kind = null,
        public ?PurchaseTaxTreatment $treatment = null,
        public string $text = '',
        public int $page = 1,
    ) {
        if (!\in_array($tab, self::TABS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown tab "%s".', $tab));
        }
    }

    /**
     * The same filter on another tab, from its first page.
     */
    public function onTab(string $tab): self
    {
        return new self($tab, $this->firmId, $this->month, $this->supplierId, $this->kind, $this->treatment, $this->text, 1);
    }

    /**
     * A month as YYYY-MM, or none for anything else.
     */
    public static function monthOf(string $value): ?string
    {
        return 1 === preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $value) ? $value : null;
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
