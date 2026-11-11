<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Purchasing\Domain\ExchangeRate;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\VatRecapLine;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * What a person, the API or a reader of the file gives for a received
 * document. The supplier and a credit note's original are named by id. An
 * exchange rate given wins; without one, a document in another currency takes
 * the published rate of its tax point.
 */
final readonly class ReceivedDocumentInput
{
    /**
     * @param list<VatRecapLine> $vatRecap
     */
    public function __construct(
        public ReceivedDocumentKind $kind,
        public PurchaseTaxTreatment $treatment,
        public ?string $supplierId,
        public string $supplierNumber,
        public string $variableSymbol,
        public ?\DateTimeImmutable $issueDate,
        public ?\DateTimeImmutable $taxPointDate,
        public ?\DateTimeImmutable $dueDate,
        public ?\DateTimeImmutable $receivedDate,
        public Currency $currency,
        public ?Money $total,
        public ?ExchangeRate $exchangeRate,
        public ?\DateTimeImmutable $exchangeRateDate,
        public array $vatRecap,
        public ?string $originalDocumentId = null,
        public ?string $supplyCode = null,
    ) {
    }
}
