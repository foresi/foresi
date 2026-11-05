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
 * What can change on an invoice after it is issued. A tax point only on a tax
 * document: an advance invoice has none.
 */
final readonly class InvoiceChange
{
    public function __construct(
        public Party $customerParty,
        public DocumentParty $customer,
        public \DateTimeImmutable $issueDate,
        public ?\DateTimeImmutable $taxPointDate,
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

    /**
     * The day the VAT rates of the lines are taken on: the tax point, or the issue
     * date of a document that has none, such as an advance invoice.
     */
    public function rateDate(): \DateTimeImmutable
    {
        return $this->taxPointDate ?? $this->issueDate;
    }

    /**
     * @param callable(string): string $text applied to the order number, the description and the texts before and after the lines
     */
    public function withTexts(callable $text): self
    {
        return new self(
            $this->customerParty,
            $this->customer,
            $this->issueDate,
            $this->taxPointDate,
            $this->dueDate,
            $this->paymentMethod,
            $this->bankAccount,
            $this->variableSymbol,
            $this->constantSymbol,
            $text($this->orderNumber),
            $text($this->description),
            $text($this->textBefore),
            $text($this->textAfter),
            $this->language,
        );
    }

    public function withVariableSymbol(string $variableSymbol): self
    {
        return new self(
            $this->customerParty,
            $this->customer,
            $this->issueDate,
            $this->taxPointDate,
            $this->dueDate,
            $this->paymentMethod,
            $this->bankAccount,
            $variableSymbol,
            $this->constantSymbol,
            $this->orderNumber,
            $this->description,
            $this->textBefore,
            $this->textAfter,
            $this->language,
        );
    }
}
