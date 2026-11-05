<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\LinkAudience;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;

/**
 * What the public page of a sent document shows, as frozen when it was sent:
 * scalars only, read across tenants without making one active. The payment state
 * and what remains to pay are today's, of the invoice as it stands now; a tax
 * document on payment is paid by its payment, as its PDF says, and an invoice its
 * advances cover in full is settled by them. A corrective tax document shows what
 * it corrects and its differences; a credit note asks for nothing, a debit note
 * for what it adds.
 */
final readonly class PublicDocument
{
    public function __construct(
        public string $sendId,
        public string $tenantId,
        public LinkAudience $audience,
        public string $issuerName,
        public string $number,
        public string $customerName,
        public int $amountMinor,
        public Currency $currency,
        public \DateTimeImmutable $dueDate,
        public DocumentLanguage $language,
        public string $pdfKey,
        public string $pdfSha256,
        public PaymentMethod $paymentMethod,
        public ?string $bankAccount,
        public ?string $domesticAccount,
        public string $variableSymbol,
        public ?string $paymentCode,
        public PaymentState $payment,
        /** What is still to pay in minor units, never below zero. */
        public int $remainingMinor,
        /** The day the last payment not cancelled came in, or null without one. */
        public ?\DateTimeImmutable $lastPaidOn,
        public DocumentKind $kind,
        /** Whether lines of the invoice deduct advances. */
        public bool $deductsAdvances,
        /** The number of the document a corrective tax document corrects. */
        public ?string $correctedNumber = null,
        public ?string $correctionReason = null,
        /** The base, tax and total of the document: on a correction, its differences. */
        public int $netMinor = 0,
        public int $vatMinor = 0,
    ) {
    }

    public function isCorrection(): bool
    {
        return DocumentKind::CorrectionDocument === $this->kind;
    }

    /**
     * A corrective tax document below zero: as its PDF says, it asks for no payment.
     */
    public function isCreditNote(): bool
    {
        return $this->isCorrection() && $this->amountMinor < 0;
    }

    /**
     * Whether the document asks for money: a payable kind, or a debit note.
     */
    public function asksForPayment(): bool
    {
        return $this->kind->isPayable() || ($this->isCorrection() && 0 < $this->amountMinor);
    }

    /**
     * Its advances cover it in full: as its PDF says, there is nothing to pay, and a
     * total below zero is a difference to refund.
     */
    public function isSettledByAdvances(): bool
    {
        return $this->deductsAdvances && $this->amountMinor <= 0;
    }
}
