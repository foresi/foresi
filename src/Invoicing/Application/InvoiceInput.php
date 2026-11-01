<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\InvoiceChange;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\Iban;
use App\Party\Domain\Party;
use App\Party\Domain\PaymentMethod;

/**
 * What a save asks for, on a new invoice and on an edit alike. Dates are calendar
 * dates: the day as written counts, in whatever time zone the object holds. Without
 * a series a new invoice takes the default one; a typed number is used as typed.
 * On an edit, no series and no typed number keep the ones the invoice has.
 *
 * Tax point and due date may be missing here, because IssueRules refuses that.
 */
final readonly class InvoiceInput
{
    /** @var list<LineInput> */
    public array $lines;

    /**
     * @param array<LineInput> $lines                       keys are discarded
     * @param bool             $variableSymbolFollowsNumber the symbol was not typed: the save derives it from
     *                                                      the number it really gives, which a form only previews
     */
    public function __construct(
        public string $firmId,
        public ?string $seriesId,
        public ?string $typedNumber,
        public string $customerId,
        public ?DocumentParty $customerOnDocument,
        public \DateTimeImmutable $issueDate,
        public ?\DateTimeImmutable $taxPointDate,
        public ?\DateTimeImmutable $dueDate,
        public PaymentMethod $paymentMethod,
        public ?string $bankAccount,
        public string $variableSymbol,
        public string $constantSymbol,
        public string $orderNumber,
        public string $description,
        public string $textBefore,
        public string $textAfter,
        public DocumentLanguage $language,
        array $lines,
        public bool $variableSymbolFollowsNumber = false,
    ) {
        $this->lines = array_values($lines);
    }

    /**
     * The editable fields of a document, once IssueRules accepted this input.
     */
    public function change(Party $customerParty, DocumentParty $customer): InvoiceChange
    {
        return new InvoiceChange(
            $customerParty,
            $customer,
            $this->issueDate,
            $this->taxPointDate ?? throw new \LogicException('IssueRules refuses a missing tax point.'),
            $this->dueDate ?? throw new \LogicException('IssueRules refuses a missing due date.'),
            $this->paymentMethod,
            PaymentMethod::BankTransfer === $this->paymentMethod && null !== $this->bankAccount ? Iban::fromString($this->bankAccount)->toString() : null,
            $this->variableSymbol,
            $this->constantSymbol,
            $this->orderNumber,
            $this->description,
            $this->textBefore,
            $this->textAfter,
            $this->language,
        );
    }
}
