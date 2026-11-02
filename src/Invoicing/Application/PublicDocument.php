<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\LinkAudience;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;

/**
 * What the public page of a sent document shows, as frozen when it was sent:
 * scalars only, read across tenants without making one active.
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
    ) {
    }
}
