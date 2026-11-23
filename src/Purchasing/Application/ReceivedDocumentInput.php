<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application;

use Foresi\Purchasing\Domain\ExchangeRate;
use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\ReceivedDocumentLineInput;
use Foresi\Purchasing\Domain\VatRecapLine;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * What a person, the API or a reader of the file gives for a received
 * document. The supplier and a credit note's original are named by id. An
 * exchange rate given wins; without one, a document in another currency takes
 * the published rate of its tax point. The lines split the bases of the VAT
 * recap: null keeps the stored ones, an empty list removes them.
 */
final readonly class ReceivedDocumentInput
{
    /**
     * @param list<VatRecapLine>                   $vatRecap
     * @param list<ReceivedDocumentLineInput>|null $lines
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
        public ?array $lines = null,
    ) {
    }
}
