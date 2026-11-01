<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\Party;
use App\Party\Domain\PaymentMethod;

/**
 * What can change on an invoice after it is issued.
 */
final readonly class InvoiceChange
{
    public function __construct(
        public Party $customerParty,
        public DocumentParty $customer,
        public \DateTimeImmutable $issueDate,
        public \DateTimeImmutable $taxPointDate,
        public \DateTimeImmutable $dueDate,
        public PaymentMethod $paymentMethod,
        public ?string $bankAccount,
        public string $variableSymbol,
        public string $constantSymbol,
        public string $orderNumber,
        public string $description,
        public string $textBefore,
        public string $textAfter,
        public DocumentLanguage $language,
    ) {
    }
}
