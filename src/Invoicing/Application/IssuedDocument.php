<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\RateSummary;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * A read-only view of an issued invoice, its lines and rate summaries, for the
 * PDF and the country code. Everything comes from what is stored, except what a
 * reminder asks for once part of the invoice is paid.
 */
final readonly class IssuedDocument
{
    /**
     * @param list<InvoiceLine>                                                         $lines
     * @param list<RateSummary>                                                         $summaries
     * @param string|null                                                               $advanceNumber the advance invoice a tax document on payment taxes a payment of
     * @param array<string, array{number: string, variableSymbol: string, taxed: bool}> $deposits      what deduction lines deduct, by id:
     *                                                                                                 a tax document is taxed, an advance invoice is not
     */
    public function __construct(
        public string $id,
        public DocumentKind $kind,
        public string $number,
        public VatRegime $regime,
        public TotalRounding $roundingPolicy,
        public Currency $currency,
        public string $exchangeRate,
        public DocumentParty $customer,
        public DocumentParty $issuer,
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
        public array $lines,
        public array $summaries,
        public Money $net,
        public Money $vat,
        public Money $gross,
        public Money $rounding,
        public Money $payable,
        public bool $trashed = false,
        public ?Money $leftToPay = null,
        public ?string $advanceNumber = null,
        public bool $deductsAdvances = false,
        public array $deposits = [],
    ) {
    }

    /**
     * What the customer is asked to pay: what is left of a part-paid invoice in a
     * reminder, else the whole payable.
     */
    public function toPay(): Money
    {
        return $this->leftToPay ?? $this->payable;
    }
}
