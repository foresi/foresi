<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Country\CzechRepublic\Invoicing;

use App\Country\CzechRepublic\Invoicing\CzechInvoicing;
use App\Country\CzechRepublic\Invoicing\IsdocWriter;
use App\Invoicing\Application\IssuedDocument;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tests\Invoicing\Application\InvoiceTestCase;
use App\Tests\Support\IsdocSchema;
use PHPUnit\Framework\Attributes\DataProvider;

final class IsdocWriterTest extends InvoiceTestCase
{
    private const string NS = 'http://isdoc.cz/namespace/2013';

    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        foreach (['transfer21', 'mixed', 'grossPrice', 'domesticReverseCharge', 'crossBorderReverseCharge', 'zeroValue', 'nonPayer', 'personCustomer', 'rounding', 'orderNumber', 'noOrderNumber', 'awkwardText', 'exempt', 'cash', 'discount', 'negativeLine'] as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    #[DataProvider('documents')]
    public function testValidatesAgainstTheSchema(string $scenario): void
    {
        IsdocSchema::assertValid(new IsdocWriter()->write($this->scenario($scenario)));
    }

    /**
     * The schema itself is checked, or a missing check would look like a pass.
     */
    public function testTheSchemaRefusesABrokenDocument(): void
    {
        $xml = new IsdocWriter()->write($this->scenario('transfer21'));
        $broken = str_replace('<DocumentType>1</DocumentType>', '<DocumentType>9</DocumentType>', $xml);

        self::assertNotSame($xml, $broken);
        self::assertNotSame([], IsdocSchema::errors($broken));
    }

    public function testTheHeaderIsWhatTheStandardAsksFor(): void
    {
        $document = $this->scenario('transfer21');
        $xml = new IsdocWriter()->write($document);
        $path = $this->path($xml);

        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        self::assertSame('6.0.2', $path->evaluate('string(/i:Invoice/@version)'));
        self::assertSame('1', $this->text($path, '/i:Invoice/i:DocumentType'));
        self::assertSame($document->number, $this->text($path, '/i:Invoice/i:ID'));
        self::assertSame($document->id, $this->text($path, '/i:Invoice/i:UUID'));
        self::assertSame('Foresi', $this->text($path, '/i:Invoice/i:IssuingSystem'));
        self::assertSame('2026-11-01', $this->text($path, '/i:Invoice/i:IssueDate'));
        self::assertSame('2026-11-01', $this->text($path, '/i:Invoice/i:TaxPointDate'));
        self::assertSame(1, $this->nodes($path, '/i:Invoice/i:ElectronicPossibilityAgreementReference'));
        self::assertSame('', $this->text($path, '/i:Invoice/i:ElectronicPossibilityAgreementReference'));
    }

    public function testTheCountryWritesItAsTheElectronicFile(): void
    {
        $document = $this->scenario('transfer21');
        $file = self::getContainer()->get(CzechInvoicing::class)->electronicFile($document);

        self::assertSame('invoice.isdoc', $file->name);
        self::assertSame('text/xml', $file->mime);
        IsdocSchema::assertValid($file->content);
        self::assertMatchesRegularExpression('/^Foresi \d+\.\d+\.\d+$/', $this->text($this->path($file->content), '/i:Invoice/i:IssuingSystem'));
    }

    public function testAmountsAreTheStoredOnes(): void
    {
        $document = $this->scenario('mixed');
        $path = $this->path(new IsdocWriter()->write($document));
        $total = '/i:Invoice/i:LegalMonetaryTotal/';

        self::assertSame('2000.00', $this->text($path, $total.'i:TaxExclusiveAmount'));
        self::assertSame('2330.00', $this->text($path, $total.'i:TaxInclusiveAmount'));
        self::assertSame('2330.00', $this->text($path, $total.'i:DifferenceTaxInclusiveAmount'));
        self::assertSame('2330.00', $this->text($path, $total.'i:PayableAmount'));
        self::assertSame('0.00', $this->text($path, $total.'i:PaidDepositsAmount'));
        self::assertSame('330.00', $this->text($path, '/i:Invoice/i:TaxTotal/i:TaxAmount'));

        foreach ($document->lines as $index => $line) {
            $row = '/i:Invoice/i:InvoiceLines/i:InvoiceLine['.($index + 1).']/';
            self::assertSame((string) ($index + 1), $this->text($path, $row.'i:ID'));
            self::assertSame($this->decimal($line->net()), $this->text($path, $row.'i:LineExtensionAmount'));
            self::assertSame($this->decimal($line->gross()), $this->text($path, $row.'i:LineExtensionAmountTaxInclusive'));
            self::assertSame($this->decimal($line->vat()), $this->text($path, $row.'i:LineExtensionTaxAmount'));
            self::assertSame($this->decimal($line->unitNet()), $this->text($path, $row.'i:UnitPrice'));
        }
        self::assertSame('21.00', $this->text($path, '/i:Invoice/i:InvoiceLines/i:InvoiceLine[1]/i:ClassifiedTaxCategory/i:Percent'));
        self::assertSame('12.00', $this->text($path, '/i:Invoice/i:InvoiceLines/i:InvoiceLine[2]/i:ClassifiedTaxCategory/i:Percent'));
        self::assertSame(2, $this->nodes($path, '/i:Invoice/i:TaxTotal/i:TaxSubTotal'));
        $first = '/i:Invoice/i:TaxTotal/i:TaxSubTotal[1]/';
        self::assertSame('1000.00', $this->text($path, $first.'i:TaxableAmount'));
        self::assertSame('210.00', $this->text($path, $first.'i:TaxAmount'));
        self::assertSame('1210.00', $this->text($path, $first.'i:TaxInclusiveAmount'));
        self::assertSame('1000.00', $this->text($path, $first.'i:DifferenceTaxableAmount'));
        self::assertSame('0.00', $this->text($path, $first.'i:AlreadyClaimedTaxAmount'));
        self::assertSame('21.00', $this->text($path, $first.'i:TaxCategory/i:Percent'));
    }

    /**
     * Stored amounts of a line priced with VAT stand as stored; the calculation method says how they arose.
     */
    public function testAGrossPriceIsWrittenAsCalculatedFromGross(): void
    {
        $path = $this->path(new IsdocWriter()->write($this->scenario('grossPrice')));
        $row = '/i:Invoice/i:InvoiceLines/i:InvoiceLine[1]/';

        self::assertSame('1', $this->text($path, $row.'i:ClassifiedTaxCategory/i:VATCalculationMethod'));
        self::assertSame('1000.00', $this->text($path, $row.'i:LineExtensionAmount'));
        self::assertSame('1210.00', $this->text($path, $row.'i:LineExtensionAmountTaxInclusive'));
        self::assertSame('1210.00', $this->text($path, $row.'i:UnitPriceTaxInclusive'));

        $net = $this->path(new IsdocWriter()->write($this->scenario('transfer21')));
        self::assertSame('0', $this->text($net, $row.'i:ClassifiedTaxCategory/i:VATCalculationMethod'));
    }

    public function testRoundingIsNoLineAndNoSubtotal(): void
    {
        $document = $this->scenario('rounding');
        $path = $this->path(new IsdocWriter()->write($document));
        $total = '/i:Invoice/i:LegalMonetaryTotal/';

        self::assertSame(1, $this->nodes($path, '//i:InvoiceLine'));
        self::assertSame(1, $this->nodes($path, '//i:TaxSubTotal'));
        self::assertSame('1260.00', $this->text($path, $total.'i:TaxExclusiveAmount'));
        self::assertSame('1524.60', $this->text($path, $total.'i:TaxInclusiveAmount'));
        self::assertSame('0.40', $this->text($path, $total.'i:PayableRoundingAmount'));
        self::assertSame('1525.00', $this->text($path, $total.'i:PayableAmount'));
        self::assertSame('1260.00', $this->text($path, '//i:InvoiceLine/i:LineExtensionAmount'));
        self::assertSame(0, $this->nodes($path, '//*[contains(text(), "Zaokrouhlení")]'));
    }

    public function testADownwardRoundingKeepsItsSign(): void
    {
        $path = $this->path(new IsdocWriter()->write($this->issued([$this->line('1000.37')])));

        self::assertSame('1210.45', $this->text($path, '//i:LegalMonetaryTotal/i:TaxInclusiveAmount'));
        self::assertSame('-0.45', $this->text($path, '//i:LegalMonetaryTotal/i:PayableRoundingAmount'));
        self::assertSame('1210.00', $this->text($path, '//i:LegalMonetaryTotal/i:PayableAmount'));
    }

    public function testReverseChargeDocumentIsNotVatApplicable(): void
    {
        $path = $this->path(new IsdocWriter()->write($this->scenario('domesticReverseCharge')));

        self::assertSame('false', $this->text($path, '/i:Invoice/i:VATApplicable'));
        self::assertSame('false', $this->text($path, '//i:InvoiceLine/i:ClassifiedTaxCategory/i:VATApplicable'));
        self::assertSame('0.00', $this->text($path, '//i:InvoiceLine/i:ClassifiedTaxCategory/i:Percent'));
        self::assertSame('0.00', $this->text($path, '//i:InvoiceLine/i:LineExtensionTaxAmount'));
        self::assertSame('false', $this->text($path, '//i:TaxSubTotal/i:TaxCategory/i:VATApplicable'));
        self::assertStringContainsString('daň odvede zákazník', $this->text($path, '/i:Invoice/i:Note'));
    }

    public function testAZeroValueDocumentIsNotVatApplicable(): void
    {
        $path = $this->path(new IsdocWriter()->write($this->scenario('zeroValue')));

        self::assertSame('false', $this->text($path, '/i:Invoice/i:VATApplicable'));
        // The rule of the standard: on a document that is not, no line is. The rate stays what is stored.
        self::assertSame('false', $this->text($path, '//i:InvoiceLine/i:ClassifiedTaxCategory/i:VATApplicable'));
        self::assertSame('21.00', $this->text($path, '//i:InvoiceLine/i:ClassifiedTaxCategory/i:Percent'));
        self::assertSame('0.00', $this->text($path, '//i:LegalMonetaryTotal/i:PayableAmount'));
    }

    public function testAMixOfSuppliesIsVatApplicableOnTheChargedLinesOnly(): void
    {
        $document = $this->issued([$this->line(), $this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '4')]);
        $path = $this->path(new IsdocWriter()->write($document));

        self::assertSame('true', $this->text($path, '/i:Invoice/i:VATApplicable'));
        self::assertSame('true', $this->text($path, '//i:InvoiceLine[1]/i:ClassifiedTaxCategory/i:VATApplicable'));
        self::assertSame('false', $this->text($path, '//i:InvoiceLine[2]/i:ClassifiedTaxCategory/i:VATApplicable'));
        self::assertSame(2, $this->nodes($path, '//i:TaxSubTotal'));
        self::assertSame(1, $this->nodes($path, '//i:LocalReverseChargeFlag'));
    }

    public function testDomesticReverseChargeCarriesItsCode(): void
    {
        $path = $this->path(new IsdocWriter()->write($this->scenario('domesticReverseCharge')));

        self::assertSame('4', $this->text($path, '//i:InvoiceLine/i:ClassifiedTaxCategory/i:LocalReverseCharge/i:LocalReverseChargeCode'));
        self::assertSame('true', $this->text($path, '//i:TaxSubTotal/i:TaxCategory/i:LocalReverseChargeFlag'));
    }

    public function testCrossBorderReverseChargeCarriesNoDomesticCode(): void
    {
        $path = $this->path(new IsdocWriter()->write($this->scenario('crossBorderReverseCharge')));

        self::assertSame(0, $this->nodes($path, '//i:LocalReverseCharge'));
        self::assertSame(0, $this->nodes($path, '//i:LocalReverseChargeFlag'));
        self::assertSame('false', $this->text($path, '/i:Invoice/i:VATApplicable'));
        self::assertStringContainsString('daň odvede zákazník', $this->text($path, '/i:Invoice/i:Note'));
        self::assertSame('DE', $this->text($path, '//i:AccountingCustomerParty//i:Country/i:IdentificationCode'));
        self::assertSame('Německo', $this->text($path, '//i:AccountingCustomerParty//i:Country/i:Name'));
    }

    public function testANonPayerDocumentIsNotVatApplicableOnAnyLine(): void
    {
        $path = $this->path(new IsdocWriter()->write($this->scenario('nonPayer')));

        self::assertSame('false', $this->text($path, '/i:Invoice/i:VATApplicable'));
        self::assertSame(0, $this->nodes($path, '//i:ClassifiedTaxCategory[i:VATApplicable != "false"]'));
        self::assertSame('0.00', $this->text($path, '/i:Invoice/i:TaxTotal/i:TaxAmount'));
        // ISDOC rule A.5: a document that is no tax document has no tax point.
        self::assertSame(0, $this->nodes($path, '/i:Invoice/i:TaxPointDate'));
        self::assertSame(0, $this->nodes($path, '//i:AccountingSupplierParty//i:PartyTaxScheme'));
    }

    public function testAnExemptLineSaysSoInItsVatNote(): void
    {
        $path = $this->path(new IsdocWriter()->write($this->scenario('exempt')));

        self::assertStringContainsString('osvobozeno', $this->text($path, '//i:InvoiceLine/i:VATNote'));
        self::assertSame('false', $this->text($path, '//i:InvoiceLine/i:ClassifiedTaxCategory/i:VATApplicable'));
        self::assertSame('0.00', $this->text($path, '//i:InvoiceLine/i:ClassifiedTaxCategory/i:Percent'));
    }

    public function testHomeCurrencyHasRateOneAndNoCurrElements(): void
    {
        $path = $this->path(new IsdocWriter()->write($this->scenario('rounding')));

        self::assertSame('CZK', $this->text($path, '/i:Invoice/i:LocalCurrencyCode'));
        self::assertSame('1', $this->text($path, '/i:Invoice/i:CurrRate'));
        self::assertSame('1', $this->text($path, '/i:Invoice/i:RefCurrRate'));
        self::assertSame(0, $this->nodes($path, '/i:Invoice/i:ForeignCurrencyCode'));
        self::assertSame(0, $this->nodes($path, '//*[substring(local-name(), string-length(local-name()) - 3) = "Curr"]'));
    }

    public function testAPersonWithoutARegistrationNumberHasAnEmptyIdentification(): void
    {
        $path = $this->path(new IsdocWriter()->write($this->scenario('personCustomer')));

        self::assertSame(1, $this->nodes($path, '//i:AccountingCustomerParty/i:Party/i:PartyIdentification/i:ID'));
        self::assertSame('', $this->text($path, '//i:AccountingCustomerParty/i:Party/i:PartyIdentification/i:ID'));
        self::assertSame(0, $this->nodes($path, '//i:AccountingCustomerParty/i:Party/i:PartyTaxScheme'));
    }

    public function testTheOrderNumberIsAnOrderReferenceOnlyWhenThereIsOne(): void
    {
        $with = $this->path(new IsdocWriter()->write($this->scenario('orderNumber')));
        $without = $this->path(new IsdocWriter()->write($this->scenario('noOrderNumber')));

        self::assertSame('PO-77', $this->text($with, '/i:Invoice/i:OrderReferences/i:OrderReference/i:ExternalOrderID'));
        self::assertSame($this->text($with, '/i:Invoice/i:ID'), $this->text($with, '/i:Invoice/i:OrderReferences/i:OrderReference/i:SalesOrderID'));
        self::assertSame(0, $this->nodes($without, '/i:Invoice/i:OrderReferences'));
    }

    public function testBankPaymentCarriesDueDateAccountAndSymbols(): void
    {
        $path = $this->path(new IsdocWriter()->write($this->scenario('rounding')));
        $payment = '/i:Invoice/i:PaymentMeans/i:Payment/';

        self::assertSame('1525.00', $this->text($path, $payment.'i:PaidAmount'));
        self::assertSame('42', $this->text($path, $payment.'i:PaymentMeansCode'));
        self::assertSame('2026-11-15', $this->text($path, $payment.'i:Details/i:PaymentDueDate'));
        self::assertSame('19-2000145399', $this->text($path, $payment.'i:Details/i:ID'));
        self::assertSame('0800', $this->text($path, $payment.'i:Details/i:BankCode'));
        self::assertSame('Česká spořitelna, a.s.', $this->text($path, $payment.'i:Details/i:Name'));
        self::assertSame('GIBACZPX', $this->text($path, $payment.'i:Details/i:BIC'));
        self::assertSame(self::IBAN, $this->text($path, $payment.'i:Details/i:IBAN'));
        self::assertSame('20260001', $this->text($path, $payment.'i:Details/i:VariableSymbol'));
        self::assertSame('0308', $this->text($path, $payment.'i:Details/i:ConstantSymbol'));
    }

    /**
     * ISDOC rule A.13: payment means tell the recipient to pay, which only a bank account asks of them.
     */
    public function testCashHasNoPaymentMeans(): void
    {
        $path = $this->path(new IsdocWriter()->write($this->scenario('cash')));

        self::assertSame(0, $this->nodes($path, '/i:Invoice/i:PaymentMeans'));
    }

    public function testTextIsEscapedAndKept(): void
    {
        $xml = new IsdocWriter()->write($this->scenario('awkwardText'));
        $path = $this->path($xml);

        self::assertSame('A&B <b>příliš</b> žluťoučký', $this->text($path, '//i:InvoiceLine/i:Item/i:Description'));
        self::assertStringContainsString('&amp;', $xml);
        self::assertStringNotContainsString('<b>', $xml);
    }

    public function testAControlCharacterDoesNotBreakTheFile(): void
    {
        $document = $this->issued([$this->line()]);
        // A document issued before the rule that refuses such text.
        $this->em->getConnection()->executeStatement('UPDATE invoice_line SET text = :text', ['text' => "Work\x01 done"]);
        $document = self::getContainer()->get(IssuedDocumentFactory::class)->of($document->id);

        $path = $this->path(new IsdocWriter()->write($document));

        self::assertSame('Work done', $this->text($path, '//i:InvoiceLine/i:Item/i:Description'));
    }

    public function testADiscountStaysInTheUnitPriceAndIsNamedOnTheLine(): void
    {
        $path = $this->path(new IsdocWriter()->write($this->scenario('discount')));

        // A unit price in ISDOC is the price the line comes to: times the quantity it gives the total.
        self::assertSame('900.00', $this->text($path, '//i:InvoiceLine/i:UnitPrice'));
        self::assertSame('1800.00', $this->text($path, '//i:InvoiceLine/i:LineExtensionAmount'));
        self::assertSame('Sleva 10 %', $this->text($path, '//i:InvoiceLine/i:Note'));
    }

    public function testElementsArePrintedDataToo(): void
    {
        $document = $this->issued([$this->line()], [
            'orderNumber' => 'PO-77',
            'description' => 'Printed description',
            'textBefore' => 'Printed before',
            'textAfter' => 'Printed after',
        ]);
        $path = $this->path(new IsdocWriter()->write($document));

        $note = $this->text($path, '/i:Invoice/i:Note');
        self::assertStringContainsString('Printed description', $note);
        self::assertStringContainsString('Printed before', $note);
        self::assertStringContainsString('Printed after', $note);

        foreach (['AccountingSupplierParty' => $document->issuer, 'AccountingCustomerParty' => $document->customer] as $element => $party) {
            $base = '//i:'.$element.'/i:Party/';
            self::assertSame($party->name, $this->text($path, $base.'i:PartyName/i:Name'));
            self::assertSame((string) $party->registrationNumber, $this->text($path, $base.'i:PartyIdentification/i:ID'));
            self::assertSame((string) $party->vatId, $this->text($path, $base.'i:PartyTaxScheme/i:CompanyID'));
            self::assertSame('VAT', $this->text($path, $base.'i:PartyTaxScheme/i:TaxScheme'));
            self::assertSame($party->address->city, $this->text($path, $base.'i:PostalAddress/i:CityName'));
            self::assertSame($party->address->postalCode, $this->text($path, $base.'i:PostalAddress/i:PostalZone'));
            self::assertSame($party->address->countryCode, $this->text($path, $base.'i:PostalAddress/i:Country/i:IdentificationCode'));
            $street = $this->text($path, $base.'i:PostalAddress/i:StreetName').' '.$this->text($path, $base.'i:PostalAddress/i:BuildingNumber');
            self::assertSame($party->address->street, $street);
        }
        self::assertSame($document->issuer->registerEntry, $this->text($path, '//i:AccountingSupplierParty//i:RegisterIdentification/i:Preformatted'));
        self::assertSame('billing@example.com', $this->text($path, '//i:AccountingCustomerParty//i:Contact/i:ElectronicMail'));

        $line = $document->lines[0];
        self::assertSame($line->text(), $this->text($path, '//i:InvoiceLine/i:Item/i:Description'));
        self::assertSame('1.0000', $this->text($path, '//i:InvoiceLine/i:InvoicedQuantity'));
        self::assertSame('h', $path->evaluate('string(//i:InvoiceLine/i:InvoicedQuantity/@unitCode)'));
    }

    private function scenario(string $name): IssuedDocument
    {
        $reverse = fn (?string $code): LineInput => $this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: $code);

        return match ($name) {
            'transfer21' => $this->issued([$this->line()]),
            'mixed' => $this->issued([$this->line(), $this->line(kind: VatRateKind::Reduced)]),
            'grossPrice' => $this->issued([$this->line('1210.00', PriceType::WithVat)]),
            'domesticReverseCharge' => $this->issued([$reverse('4')]),
            'crossBorderReverseCharge' => $this->issued([$reverse(null)], ['customerId' => $this->foreignCustomer()->id()]),
            'zeroValue' => $this->issued([$this->line('0.00')]),
            'nonPayer' => $this->issuedByNonPayer(),
            'personCustomer' => $this->issued([$this->line()], ['customerId' => $this->person()->id()]),
            'rounding' => $this->issued([$this->line('1260.00')]),
            'orderNumber' => $this->issued([$this->line()], ['orderNumber' => 'PO-77']),
            'noOrderNumber' => $this->issued([$this->line()], ['orderNumber' => '']),
            'awkwardText' => $this->issued([$this->line(text: 'A&B <b>příliš</b> žluťoučký')], ['description' => 'R&D <i>ěšč</i>']),
            'exempt' => $this->issued([$this->line(treatment: TaxTreatment::Exempt, kind: null)]),
            'cash' => $this->issued([$this->line()], ['paymentMethod' => PaymentMethod::Cash, 'bankAccount' => null]),
            'discount' => $this->issued([new LineInput('Work', Quantity::fromString('2'), 'h', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('10'))]),
            'negativeLine' => $this->issued([$this->line('1000.00'), $this->line('-100.00')]),
            default => throw new \LogicException('No scenario '.$name),
        };
    }

    /**
     * @param list<LineInput>      $lines
     * @param array<string, mixed> $overrides
     */
    private function issued(array $lines, array $overrides = []): IssuedDocument
    {
        $invoice = $this->issue->issue($this->input($lines, $overrides));

        return self::getContainer()->get(IssuedDocumentFactory::class)->of($invoice->id());
    }

    private function issuedByNonPayer(): IssuedDocument
    {
        $firm = $this->firm(VatStatus::NotRegistered, null, registrationNumber: '12345687');
        $this->em->flush();

        return $this->issued(
            [$this->line(treatment: TaxTreatment::NotApplicable, kind: null)],
            ['firmId' => $firm->id(), 'seriesId' => null, 'variableSymbol' => ''],
        );
    }

    private function foreignCustomer(): Party
    {
        $customer = $this->customer('DE123456789', new Address('Beispielstrasse 1', 'Berlin', '10115', 'DE'));
        $this->em->flush();

        return $customer;
    }

    private function person(): Party
    {
        $party = new Party(PartyKind::Person, 'Example Person', null, null, '');
        $this->em->persist($party);
        $this->em->persist(new PartyAddress($party, AddressType::Billing, new Address('Home 3', 'Example Town', '300 00', 'CZ')));
        $this->em->flush();

        return $party;
    }

    private function decimal(Money $money): string
    {
        return \sprintf('%d.%02d', intdiv($money->minor(), 100), abs($money->minor()) % 100);
    }

    private function path(string $xml): \DOMXPath
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $path = new \DOMXPath($document);
        $path->registerNamespace('i', self::NS);

        return $path;
    }

    private function text(\DOMXPath $path, string $query): string
    {
        $value = $path->evaluate('string('.$query.')');
        \assert(\is_string($value));

        return $value;
    }

    private function nodes(\DOMXPath $path, string $query): int
    {
        $nodes = $path->query($query);
        self::assertInstanceOf(\DOMNodeList::class, $nodes);

        return $nodes->count();
    }
}
