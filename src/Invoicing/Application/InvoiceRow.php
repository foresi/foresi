<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\ExportState;
use App\Shared\Domain\Money;

/**
 * One line of the invoice list, with the names as the document holds them. Overdue is
 * past the due date while something is still to pay. The deletion is
 * given in the time zone of the country that issued the document. The send mark says how
 * the newest e-mail of the invoice went.
 */
final readonly class InvoiceRow
{
    public function __construct(
        public string $id,
        public string $number,
        public string $firmName,
        public string $customerName,
        public \DateTimeImmutable $issueDate,
        public \DateTimeImmutable $dueDate,
        public Money $payable,
        public bool $delivered,
        public bool $overdue,
        public bool $trashed,
        public Money $paid,
        public PaymentState $payment,
        public ?\DateTimeImmutable $deletedAt = null,
        public ?string $deletedBy = null,
        public ?string $deletedReason = null,
        public SendMark $sendMark = SendMark::None,
        public DocumentKind $kind = DocumentKind::Invoice,
        public bool $settledByAdvances = false,
        /** What its corrective documents not in the trash add to what it asks for. */
        public ?Money $corrections = null,
        public ?\DateTimeImmutable $exportedAt = null,
        public ?\DateTimeImmutable $changedAfterExportAt = null,
    ) {
    }

    public function exportState(): ExportState
    {
        return ExportState::of($this->exportedAt, $this->changedAfterExportAt);
    }

    /**
     * What is still to pay after the corrections: below zero when there is something to refund.
     */
    public function remaining(): Money
    {
        $due = $this->corrections instanceof Money ? $this->payable->plus($this->corrections) : $this->payable;

        return $due->minus($this->paid);
    }
}
