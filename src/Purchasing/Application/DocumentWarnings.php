<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Attachment\Application\Attachments;
use App\Attachment\Domain\Attachment;
use App\Party\Domain\Party;
use App\Purchasing\Domain\ExchangeRate;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantClock;

/**
 * What a person should look at about a saved document, never a reason to
 * refuse it (ADR 0028): a file or a supplier's number seen before, a deduction
 * deadline passed or close, a simplified receipt above its limit. Asked after
 * the save, so the other documents are read as stored.
 */
final readonly class DocumentWarnings
{
    /** A simplified tax document is allowed up to 10,000 CZK with VAT (§30(1)). */
    private const int SIMPLIFIED_LIMIT_MINOR = 1_000_000;

    public function __construct(
        private DuplicateDocuments $duplicates,
        private Attachments $attachments,
        private TenantClock $clock,
    ) {
    }

    public function of(ReceivedDocument $document): ReceivedDocumentWarnings
    {
        $warnings = [];
        $file = $this->sameFile($document);
        if ([] !== $file) {
            $warnings[] = new ReceivedDocumentWarning(ReceivedDocumentWarning::DUPLICATE_FILE, $file);
        }
        $number = $this->sameSupplierNumber($document);
        if ([] !== $number) {
            $warnings[] = new ReceivedDocumentWarning(ReceivedDocumentWarning::DUPLICATE_SUPPLIER_NUMBER, $number);
        }
        $deadline = $this->deadline($document);
        if ($deadline instanceof ReceivedDocumentWarning) {
            $warnings[] = $deadline;
        }
        if ($this->simplifiedOverLimit($document)) {
            $warnings[] = new ReceivedDocumentWarning(ReceivedDocumentWarning::SIMPLIFIED_OVER_LIMIT);
        }

        return new ReceivedDocumentWarnings($warnings);
    }

    /**
     * @return list<string>
     */
    private function sameFile(ReceivedDocument $document): array
    {
        $id = $document->originalAttachmentId();
        $original = null === $id ? null : $this->attachments->get($id);

        return $original instanceof Attachment ? $this->duplicates->withFile($document->firm(), $original->sha256(), $document->id()) : [];
    }

    /**
     * @return list<string>
     */
    private function sameSupplierNumber(ReceivedDocument $document): array
    {
        $supplier = $document->supplierParty();
        $number = $document->supplierNumber();

        return $supplier instanceof Party && '' !== $number ? $this->duplicates->withSupplierNumber($document->firm(), $supplier, $number, $document->id()) : [];
    }

    private function deadline(ReceivedDocument $document): ?ReceivedDocumentWarning
    {
        $latest = $document->latestDeductionDate();
        if (!$latest instanceof \DateTimeImmutable) {
            return null;
        }
        $today = $this->clock->today()->format('Y-m-d');
        if ($latest->format('Y-m-d') < $today) {
            return new ReceivedDocumentWarning(ReceivedDocumentWarning::DEDUCTION_DEADLINE_PASSED, date: $latest);
        }
        $near = $this->clock->today()->add(new \DateInterval(\sprintf('P%dD', ReceivedDocumentWarning::DEADLINE_DAYS)))->format('Y-m-d');

        return $latest->format('Y-m-d') <= $near ? new ReceivedDocumentWarning(ReceivedDocumentWarning::DEDUCTION_DEADLINE_NEAR, date: $latest) : null;
    }

    private function simplifiedOverLimit(ReceivedDocument $document): bool
    {
        $total = $document->total();
        if (ReceivedDocumentKind::SimplifiedReceipt !== $document->kind() || !$total instanceof Money) {
            return false;
        }
        $rate = $document->exchangeRate();
        if (Currency::CZK !== $total->currency() && !$rate instanceof ExchangeRate) {
            return false;
        }
        $czk = $rate instanceof ExchangeRate ? $rate->toCzk($total) : $total;

        return $czk->minor() > self::SIMPLIFIED_LIMIT_MINOR;
    }
}
