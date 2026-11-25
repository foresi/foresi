<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application;

use Foresi\Attachment\Application\Attachments;
use Foresi\Attachment\Domain\Attachment;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Domain\Party;
use Foresi\Purchasing\Application\Mail\MailIntakes;
use Foresi\Purchasing\Domain\ExchangeRate;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

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
        private MailIntakes $intakes,
        private Attachments $attachments,
        private InstanceClock $clock,
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
        if ($this->senderUnverified($document)) {
            $warnings[] = new ReceivedDocumentWarning(ReceivedDocumentWarning::SENDER_UNVERIFIED);
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

        return $original instanceof Attachment ? $this->duplicates->withFile($original->sha256(), $document->id()) : [];
    }

    /**
     * @return list<string>
     */
    private function sameSupplierNumber(ReceivedDocument $document): array
    {
        $supplier = $document->supplierParty();
        $number = $document->supplierNumber();

        return $supplier instanceof Party && '' !== $number ? $this->duplicates->withSupplierNumber($supplier, $number, $document->id()) : [];
    }

    /**
     * Derived from the verdict stored on the intake of the mail, never stored on the document.
     */
    private function senderUnverified(ReceivedDocument $document): bool
    {
        $mail = $document->sourceMailId();

        return null !== $mail && 'failed' === $this->intakes->verdictOf($mail);
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
