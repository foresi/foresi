<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Country\CzechRepublic\Invoicing;

use App\Country\CzechRepublic\Party\CzechBankAccount;
use App\Country\CzechRepublic\Party\CzechBanks;
use App\Invoicing\Application\IssuedDocument;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\InvoiceCalculator;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\LineAmounts;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\RateSummary;
use App\Invoicing\Domain\SummaryLine;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Countries;
use App\Shared\Domain\Money;

/**
 * Writes an issued document as ISDOC 6.0.2 (isdoc.cz). The amounts are the stored
 * ones, and only the unit price with VAT, which is stored nowhere, comes from them:
 * rule A.3 of the standard lets the total win over it.
 *
 * Rounding of the total is PayableRoundingAmount alone, never a line or a subtotal.
 * A document on which no line carries VAT is not VATApplicable, and then no line is
 * (the Schematron of the standard). Otherwise, a line without a rate is not. The
 * order of the elements is the order of the XSD.
 */
final readonly class IsdocWriter
{
    private const string NAMESPACE = 'http://isdoc.cz/namespace/2013';
    private const string VERSION = '6.0.2';
    private const string INVOICE = '1';
    private const string BANK_TRANSFER = '42';
    private const string REVERSE_CHARGE_WORDS = 'daň odvede zákazník';
    private const string EXEMPT_WORDS = 'Plnění je osvobozeno od daně.';
    private const string COUNTRY_NAME_LOCALE = 'cs';
    /** Everything XML 1.0 allows; anything else in a text would leave the file unreadable. */
    private const string NOT_XML = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

    public function __construct(private string $issuingSystem = 'Foresi')
    {
    }

    public function write(IssuedDocument $document): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS(self::NAMESPACE, 'Invoice');
        $root->setAttribute('version', self::VERSION);
        $dom->appendChild($root);

        $applicable = $this->isVatApplicable($document);
        $this->add($root, 'DocumentType', self::INVOICE);
        $this->add($root, 'ID', $document->number);
        $this->add($root, 'UUID', $document->id);
        $this->add($root, 'IssuingSystem', $this->issuingSystem);
        $this->add($root, 'IssueDate', $document->issueDate->format('Y-m-d'));
        if (VatRegime::Payer === $document->regime) {
            // Rule A.5: a document that is no tax document has no tax point.
            $this->add($root, 'TaxPointDate', ($document->taxPointDate ?? throw new \LogicException('Only a tax document is written as ISDOC.'))->format('Y-m-d'));
        }
        $this->add($root, 'VATApplicable', $this->flag($applicable));
        $this->add($root, 'ElectronicPossibilityAgreementReference', '');
        $note = $this->note($document);
        if ('' !== $note) {
            $this->add($root, 'Note', $note);
        }
        $this->add($root, 'LocalCurrencyCode', $document->currency->value);
        $this->add($root, 'CurrRate', '1');
        $this->add($root, 'RefCurrRate', '1');
        $this->party($this->add($root, 'AccountingSupplierParty'), $document->issuer);
        $this->party($this->add($root, 'AccountingCustomerParty'), $document->customer);
        if ('' !== $document->orderNumber) {
            // SalesOrderID is required and is the supplier's own identifier: the customer's order number is the external one.
            $reference = $this->add($this->add($root, 'OrderReferences'), 'OrderReference');
            $this->add($reference, 'SalesOrderID', $document->number);
            $this->add($reference, 'ExternalOrderID', $document->orderNumber);
        }

        // Deducted advances are deposits in ISDOC, not lines: the lines are the supply (§A.6, §A.9).
        $supply = array_values(array_filter($document->lines, static fn (InvoiceLine $line): bool => null === $line->deducts()));
        $lines = $this->add($root, 'InvoiceLines');
        foreach ($supply as $index => $line) {
            $this->line($lines, $line, $index + 1, $applicable);
        }
        $this->deposits($root, $document);
        $this->taxTotal($root, $document, $supply);
        $this->monetaryTotal($root, $document, $supply);
        $this->payment($root, $document);

        $xml = $dom->saveXML();
        \assert(false !== $xml);

        return $xml;
    }

    private function isVatApplicable(IssuedDocument $document): bool
    {
        return array_any($document->lines, static fn (InvoiceLine $line): bool => !$line->vat()->isZero());
    }

    /**
     * Everything the document prints as text, and the words the Act asks for.
     */
    private function note(IssuedDocument $document): string
    {
        $parts = [$document->textBefore, $document->description, $document->textAfter];
        if (array_any($document->lines, static fn (InvoiceLine $line): bool => TaxTreatment::ReverseCharge === $line->treatment())) {
            $parts[] = self::REVERSE_CHARGE_WORDS;
        }

        return implode("\n", array_filter($parts, static fn (string $part): bool => '' !== $part));
    }

    private function party(\DOMElement $parent, DocumentParty $party): void
    {
        $element = $this->add($parent, 'Party');
        $this->add($this->add($element, 'PartyIdentification'), 'ID', $party->registrationNumber ?? '');
        $this->add($this->add($element, 'PartyName'), 'Name', $party->name);

        $address = $this->add($element, 'PostalAddress');
        [$street, $building] = $this->street($party->address->street);
        $this->add($address, 'StreetName', $street);
        $this->add($address, 'BuildingNumber', $building);
        $this->add($address, 'CityName', $party->address->city);
        $this->add($address, 'PostalZone', $party->address->postalCode);
        $country = $this->add($address, 'Country');
        $this->add($country, 'IdentificationCode', $party->address->countryCode);
        $this->add($country, 'Name', Countries::name($party->address->countryCode, self::COUNTRY_NAME_LOCALE));

        if (null !== $party->vatId) {
            $scheme = $this->add($element, 'PartyTaxScheme');
            $this->add($scheme, 'CompanyID', $party->vatId);
            $this->add($scheme, 'TaxScheme', 'VAT');
        }
        if ('' !== $party->registerEntry) {
            $this->add($this->add($element, 'RegisterIdentification'), 'Preformatted', $party->registerEntry);
        }
        if ('' !== $party->phone || null !== $party->email) {
            $contact = $this->add($element, 'Contact');
            if ('' !== $party->phone) {
                $this->add($contact, 'Telephone', $party->phone);
            }
            if (null !== $party->email) {
                $this->add($contact, 'ElectronicMail', $party->email);
            }
        }
    }

    /**
     * The stored address has one street field and ISDOC two.
     *
     * @return array{string, string}
     */
    private function street(string $street): array
    {
        return 1 === preg_match('/^(.*\S)\s+(\d\S*)$/Du', $street, $parts) ? [$parts[1], $parts[2]] : [$street, ''];
    }

    private function line(\DOMElement $parent, InvoiceLine $line, int $number, bool $documentApplicable): void
    {
        $element = $this->add($parent, 'InvoiceLine');
        $this->add($element, 'ID', (string) $number);
        $quantity = $this->add($element, 'InvoicedQuantity', $line->quantity()->toDecimal());
        if ('' !== $line->unit()) {
            $quantity->setAttribute('unitCode', $this->clean($line->unit()));
        }
        $this->add($element, 'LineExtensionAmount', $this->amount($line->net()));
        $this->add($element, 'LineExtensionAmountTaxInclusive', $this->amount($line->gross()));
        $this->add($element, 'LineExtensionTaxAmount', $this->amount($line->vat()));
        $this->add($element, 'UnitPrice', $this->amount($line->unitNet()));
        $this->add($element, 'UnitPriceTaxInclusive', $this->amount($this->unitGross($line)));

        $category = $this->add($element, 'ClassifiedTaxCategory');
        $this->add($category, 'Percent', $line->rate()?->decimal() ?? '0.00');
        $this->add($category, 'VATCalculationMethod', PriceType::WithVat === $line->priceType() ? '1' : '0');
        $this->add($category, 'VATApplicable', $this->flag($documentApplicable && $line->rate() instanceof \App\Invoicing\Domain\Percent));
        $code = $line->supplyCode();
        if (null !== $code) {
            $this->add($this->add($category, 'LocalReverseCharge'), 'LocalReverseChargeCode', $code);
        }

        if (0 < $line->discount()->basisPoints()) {
            $this->add($element, 'Note', \sprintf('Sleva %s %%', $this->percent($line)));
        }
        if (TaxTreatment::Exempt === $line->treatment()) {
            $this->add($element, 'VATNote', self::EXEMPT_WORDS);
        }
        $this->add($this->add($element, 'Item'), 'Description', $line->text());
    }

    private function unitGross(InvoiceLine $line): Money
    {
        return $line->quantity()->isZero() ? $line->unitNet() : $line->gross()->dividedBy($line->quantity()->toDecimal());
    }

    private function percent(InvoiceLine $line): string
    {
        return rtrim(rtrim($line->discount()->decimal(), '0'), '.');
    }

    /**
     * A paid advance without VAT once per advance; a tax document on payment once
     * per rate of it. Amounts are positive, though they are subtracted (§A.6).
     */
    private function deposits(\DOMElement $root, IssuedDocument $document): void
    {
        $untaxed = [];
        $taxed = [];
        foreach ($document->lines as $line) {
            $id = $line->deducts();
            if (null === $id || !isset($document->deposits[$id])) {
                continue;
            }
            if ($document->deposits[$id]['taxed']) {
                $taxed[$id][$line->treatment()->value][(string) $line->rate()?->decimal()][] = $line;
            } else {
                $untaxed[$id][] = $line;
            }
        }
        if ([] !== $untaxed) {
            $element = $this->add($root, 'NonTaxedDeposits');
            foreach ($untaxed as $id => $lines) {
                $deposit = $this->add($element, 'NonTaxedDeposit');
                $this->add($deposit, 'ID', $document->deposits[$id]['number']);
                $this->add($deposit, 'VariableSymbol', $document->deposits[$id]['variableSymbol']);
                $this->add($deposit, 'DepositAmount', $this->amount($this->negated($this->sum($lines, 'gross', $document))));
            }
        }
        if ([] !== $taxed) {
            $element = $this->add($root, 'TaxedDeposits');
            foreach ($taxed as $id => $treatments) {
                foreach ($treatments as $rates) {
                    foreach ($rates as $rate => $lines) {
                        $deposit = $this->add($element, 'TaxedDeposit');
                        $this->add($deposit, 'ID', $document->deposits[$id]['number']);
                        $this->add($deposit, 'VariableSymbol', $document->deposits[$id]['variableSymbol']);
                        $this->add($deposit, 'TaxableDepositAmount', $this->amount($this->negated($this->sum($lines, 'net', $document))));
                        $this->add($deposit, 'TaxInclusiveDepositAmount', $this->amount($this->negated($this->sum($lines, 'gross', $document))));
                        $category = $this->add($deposit, 'ClassifiedTaxCategory');
                        $this->add($category, 'Percent', '' === $rate ? '0.00' : $rate);
                        $this->add($category, 'VATCalculationMethod', '1');
                        $this->add($category, 'VATApplicable', $this->flag('' !== $rate));
                    }
                }
            }
        }
    }

    /**
     * Per rate: the supply, what the tax documents on payments claimed already, and
     * the difference on which VAT is due now (§37a).
     *
     * @param list<InvoiceLine> $supply
     */
    private function taxTotal(\DOMElement $root, IssuedDocument $document, array $supply): void
    {
        $total = $this->add($root, 'TaxTotal');
        $domestic = array_any($document->lines, static fn (InvoiceLine $line): bool => null !== $line->supplyCode());
        $applicable = $this->isVatApplicable($document);
        $zero = Money::ofMinor(0, $document->currency);
        $claimed = [];
        foreach ($this->summaries($this->taxedDeductions($document), $zero) as $summary) {
            $claimed[$this->key($summary)] = $summary;
        }
        $supplied = $this->summaries($supply, $zero);
        foreach ($supplied as $summary) {
            $this->subtotal($total, $summary, $claimed[$this->key($summary)] ?? null, $applicable, $domestic);
            unset($claimed[$this->key($summary)]);
        }
        // An advance at a rate the supply no longer has: all of it claimed, a difference below zero.
        foreach ($claimed as $summary) {
            $this->subtotal($total, new RateSummary($summary->treatment, $summary->rate, $zero, $zero, $zero), $summary, $applicable, $domestic);
        }
        $this->add($total, 'TaxAmount', $this->amount($document->vat));
    }

    /**
     * @param RateSummary|null $claimed the deduction lines at the rate, negative as they are stored
     */
    private function subtotal(\DOMElement $total, RateSummary $summary, ?RateSummary $claimed, bool $documentApplicable, bool $domesticReverseCharge): void
    {
        $element = $this->add($total, 'TaxSubTotal');
        $zero = '0.00';
        $base = $claimed instanceof RateSummary ? $this->negated($claimed->base) : null;
        $vat = $claimed instanceof RateSummary ? $this->negated($claimed->vat) : null;
        $gross = $claimed instanceof RateSummary ? $this->negated($claimed->gross) : null;
        $this->add($element, 'TaxableAmount', $this->amount($summary->base));
        $this->add($element, 'TaxAmount', $this->amount($summary->vat));
        $this->add($element, 'TaxInclusiveAmount', $this->amount($summary->gross));
        $this->add($element, 'AlreadyClaimedTaxableAmount', $base instanceof Money ? $this->amount($base) : $zero);
        $this->add($element, 'AlreadyClaimedTaxAmount', $vat instanceof Money ? $this->amount($vat) : $zero);
        $this->add($element, 'AlreadyClaimedTaxInclusiveAmount', $gross instanceof Money ? $this->amount($gross) : $zero);
        $this->add($element, 'DifferenceTaxableAmount', $this->amount($base instanceof Money ? $summary->base->minus($base) : $summary->base));
        $this->add($element, 'DifferenceTaxAmount', $this->amount($vat instanceof Money ? $summary->vat->minus($vat) : $summary->vat));
        $this->add($element, 'DifferenceTaxInclusiveAmount', $this->amount($gross instanceof Money ? $summary->gross->minus($gross) : $summary->gross));

        $category = $this->add($element, 'TaxCategory');
        $this->add($category, 'Percent', $summary->rate?->decimal() ?? $zero);
        $this->add($category, 'VATApplicable', $this->flag($documentApplicable && $summary->rate instanceof \App\Invoicing\Domain\Percent));
        if ($domesticReverseCharge && TaxTreatment::ReverseCharge === $summary->treatment) {
            $this->add($category, 'LocalReverseChargeFlag', 'true');
        }
    }

    /**
     * DifferenceTaxInclusiveAmount + PayableRoundingAmount - PaidDepositsAmount = PayableAmount (§A.6 b).
     *
     * @param list<InvoiceLine> $supply
     */
    private function monetaryTotal(\DOMElement $root, IssuedDocument $document, array $supply): void
    {
        $total = $this->add($root, 'LegalMonetaryTotal');
        $taxed = $this->taxedDeductions($document);
        $claimedNet = $this->negated($this->sum($taxed, 'net', $document));
        $claimedGross = $this->negated($this->sum($taxed, 'gross', $document));
        $supplyNet = $this->sum($supply, 'net', $document);
        $supplyGross = $this->sum($supply, 'gross', $document);
        $untaxed = array_values(array_filter($document->lines, static fn (InvoiceLine $line): bool => null !== $line->deducts() && !($document->deposits[$line->deducts()]['taxed'] ?? false)));
        $this->add($total, 'TaxExclusiveAmount', $this->amount($supplyNet));
        $this->add($total, 'TaxInclusiveAmount', $this->amount($supplyGross));
        $this->add($total, 'AlreadyClaimedTaxExclusiveAmount', $this->amount($claimedNet));
        $this->add($total, 'AlreadyClaimedTaxInclusiveAmount', $this->amount($claimedGross));
        $this->add($total, 'DifferenceTaxExclusiveAmount', $this->amount($supplyNet->minus($claimedNet)));
        $this->add($total, 'DifferenceTaxInclusiveAmount', $this->amount($supplyGross->minus($claimedGross)));
        $this->add($total, 'PayableRoundingAmount', $this->amount($document->rounding));
        $this->add($total, 'PaidDepositsAmount', $this->amount($this->negated($this->sum($untaxed, 'gross', $document))));
        $this->add($total, 'PayableAmount', $this->amount($document->payable));
    }

    /**
     * @return list<InvoiceLine> the lines that deduct a tax document on payment
     */
    private function taxedDeductions(IssuedDocument $document): array
    {
        return array_values(array_filter($document->lines, static fn (InvoiceLine $line): bool => null !== $line->deducts() && ($document->deposits[$line->deducts()]['taxed'] ?? false)));
    }

    /**
     * @param list<InvoiceLine> $lines
     *
     * @return list<RateSummary>
     */
    private function summaries(array $lines, Money $zero): array
    {
        return new InvoiceCalculator()->summaries(array_map(static fn (InvoiceLine $line): SummaryLine => new SummaryLine($line->treatment(), $line->rate(), new LineAmounts($line->unitNet(), $line->net(), $line->vat(), $line->gross())), $lines), $zero);
    }

    private function key(RateSummary $summary): string
    {
        return $summary->treatment->value.'|'.($summary->rate?->basisPoints() ?? '-');
    }

    /**
     * @param list<InvoiceLine> $lines
     * @param 'net'|'gross'     $amount
     */
    private function sum(array $lines, string $amount, IssuedDocument $document): Money
    {
        $sum = Money::ofMinor(0, $document->currency);
        foreach ($lines as $line) {
            $sum = $sum->plus('net' === $amount ? $line->net() : $line->gross());
        }

        return $sum;
    }

    private function negated(Money $money): Money
    {
        return Money::ofMinor(-$money->minor(), $money->currency());
    }

    /**
     * Rule A.13: PaymentMeans tell the recipient to pay, which only a bank account asks of them.
     */
    private function payment(\DOMElement $root, IssuedDocument $document): void
    {
        if (PaymentMethod::BankTransfer !== $document->paymentMethod) {
            return;
        }
        $payment = $this->add($this->add($root, 'PaymentMeans'), 'Payment');
        $this->add($payment, 'PaidAmount', $this->amount($document->payable));
        $this->add($payment, 'PaymentMeansCode', self::BANK_TRANSFER);

        $iban = $document->bankAccount ?? '';
        $account = CzechBankAccount::partsOf($iban);
        $details = $this->add($payment, 'Details');
        $this->add($details, 'PaymentDueDate', $document->dueDate->format('Y-m-d'));
        $this->add($details, 'ID', $account['number'] ?? '');
        $this->add($details, 'BankCode', $account['bankCode'] ?? '');
        $this->add($details, 'Name', CzechBanks::nameOf($account['bankCode'] ?? ''));
        $this->add($details, 'IBAN', $iban);
        $this->add($details, 'BIC', CzechBanks::bicOf($account['bankCode'] ?? ''));
        if ('' !== $document->variableSymbol) {
            $this->add($details, 'VariableSymbol', $document->variableSymbol);
        }
        if ('' !== $document->constantSymbol) {
            $this->add($details, 'ConstantSymbol', $document->constantSymbol);
        }
    }

    private function add(\DOMElement $parent, string $name, ?string $value = null): \DOMElement
    {
        $document = $parent->ownerDocument;
        \assert($document instanceof \DOMDocument);
        $element = $document->createElementNS(self::NAMESPACE, $name);
        if (null !== $value && '' !== $value) {
            $element->appendChild($document->createTextNode($this->clean($value)));
        }
        $parent->appendChild($element);

        return $element;
    }

    private function clean(string $text): string
    {
        return (string) preg_replace(self::NOT_XML, '', $text);
    }

    private function flag(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    private function amount(Money $money): string
    {
        $minor = $money->minor();
        $absolute = abs($minor);

        return \sprintf('%s%d.%02d', $minor < 0 ? '-' : '', intdiv($absolute, 100), $absolute % 100);
    }
}
