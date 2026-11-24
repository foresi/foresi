<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Unit\Reading;

use Foresi\Purchasing\Contract\Reading\DocumentFile;
use Foresi\Purchasing\Contract\Reading\ReaderFailed;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Infrastructure\Reading\FacturXReader;
use Foresi\Purchasing\Infrastructure\Reading\SmalotPdfFiles;
use Foresi\Tests\Support\ReceivedFileFixtures;
use PHPUnit\Framework\TestCase;

final class FacturXReaderTest extends TestCase
{
    private FacturXReader $reader;

    protected function setUp(): void
    {
        $this->reader = new FacturXReader(new SmalotPdfFiles());
    }

    public function testDescribesItself(): void
    {
        self::assertSame('factur_x', $this->reader->source());
        self::assertTrue($this->reader->automatic());
        self::assertTrue($this->reader->supports(new DocumentFile('a.xml', 'application/xml', '')));
        self::assertTrue($this->reader->supports(new DocumentFile('a.pdf', 'application/pdf', '')));
        self::assertFalse($this->reader->supports(new DocumentFile('a.png', 'image/png', '')));
    }

    public function testReadsEn16931Profile(): void
    {
        $reading = $this->reader->read(new DocumentFile('factur-x.xml', 'application/xml', self::cii()));

        self::assertNotNull($reading);
        self::assertSame(
            [
                'kind' => 'tax_invoice',
                'supplier_name' => 'Voice API GmbH',
                'supplier_vat_id' => 'DE123456789',
                'supplier_country' => 'DE',
                'supplier_number' => 'RE-2026-0042',
                'issue_date' => '2026-10-31',
                'due_date' => '2026-11-30',
                'currency' => 'EUR',
                'total' => 11_900,
                'vat_recap' => [['rate' => '19', 'baseMinor' => 10_000, 'vatMinor' => 1_900]],
                'bank_account' => 'DE89370400440532013000',
            ],
            array_map(static fn (array $entry): mixed => $entry['value'], $reading->toArray()),
        );
        foreach ($reading->values as $value) {
            self::assertSame('factur_x', $value->source);
        }
    }

    public function testACreditNoteHasItsKind(): void
    {
        $reading = $this->reader->read(new DocumentFile('factur-x.xml', 'application/xml', self::cii(typeCode: '381')));

        self::assertSame('credit_note', $reading?->get(ReadField::Kind)?->value);
    }

    public function testMinimumProfileGivesNoRecap(): void
    {
        $reading = $this->reader->read(new DocumentFile('factur-x.xml', 'application/xml', self::cii(withTaxRows: false, withPaymentDetails: false)));

        self::assertNotNull($reading);
        self::assertNull($reading->get(ReadField::VatRecap));
        self::assertNull($reading->get(ReadField::BankAccount));
        self::assertNull($reading->get(ReadField::DueDate));
        self::assertSame(11_900, $reading->get(ReadField::Total)?->value);
        self::assertSame('Voice API GmbH', $reading->get(ReadField::SupplierName)?->value);
    }

    public function testReadsCiiEmbeddedInPdf(): void
    {
        foreach (['factur-x.xml', 'ZUGFeRD-invoice.xml', 'xrechnung.xml'] as $name) {
            $pdf = ReceivedFileFixtures::pdf(embeddedName: $name, embeddedContent: self::cii());

            $reading = $this->reader->read(new DocumentFile('invoice.pdf', 'application/pdf', $pdf));

            self::assertSame(11_900, $reading?->get(ReadField::Total)?->value, $name);
        }
    }

    public function testAPdfWithoutTheXmlFindsNothing(): void
    {
        self::assertNull($this->reader->read(new DocumentFile('a.pdf', 'application/pdf', ReceivedFileFixtures::pdf())));
        $other = ReceivedFileFixtures::pdf(embeddedName: 'invoice.isdoc', embeddedContent: self::cii());
        self::assertNull($this->reader->read(new DocumentFile('b.pdf', 'application/pdf', $other)));
    }

    public function testXmlOfAnotherFormatFindsNothing(): void
    {
        self::assertNull($this->reader->read(new DocumentFile('a.xml', 'application/xml', '<?xml version="1.0"?><Invoice xmlns="http://isdoc.cz/namespace/2013"/>')));
    }

    public function testRefusesDoctypeAndExternalEntity(): void
    {
        $xml = str_replace('<rsm:CrossIndustryInvoice', '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><rsm:CrossIndustryInvoice', self::cii());
        $xml = str_replace('RE-2026-0042', '&e;', $xml);

        $this->expectException(ReaderFailed::class);
        $this->reader->read(new DocumentFile('factur-x.xml', 'application/xml', $xml));
    }

    public function testACorruptPdfFails(): void
    {
        $this->expectException(ReaderFailed::class);
        $this->reader->read(new DocumentFile('a.pdf', 'application/pdf', '%PDF-1.7 broken'));
    }

    public function testABadDateOrAmountDropsOnlyThatValue(): void
    {
        $xml = str_replace('<udt:DateTimeString format="102">20261031</udt:DateTimeString>', '<udt:DateTimeString format="102">20261331</udt:DateTimeString>', self::cii());
        $xml = str_replace('<ram:GrandTotalAmount>119.00</ram:GrandTotalAmount>', '<ram:GrandTotalAmount>1,19</ram:GrandTotalAmount>', $xml);

        $reading = $this->reader->read(new DocumentFile('factur-x.xml', 'application/xml', $xml));

        self::assertNotNull($reading);
        self::assertNull($reading->get(ReadField::IssueDate));
        self::assertNull($reading->get(ReadField::Total));
        self::assertSame('EUR', $reading->get(ReadField::Currency)?->value);
        self::assertSame('2026-11-30', $reading->get(ReadField::DueDate)?->value);
    }

    private static function cii(string $typeCode = '380', bool $withTaxRows = true, bool $withPaymentDetails = true): string
    {
        $tax = $withTaxRows ? '<ram:ApplicableTradeTax><ram:CalculatedAmount>19.00</ram:CalculatedAmount><ram:TypeCode>VAT</ram:TypeCode><ram:BasisAmount>100.00</ram:BasisAmount><ram:CategoryCode>S</ram:CategoryCode><ram:RateApplicablePercent>19.00</ram:RateApplicablePercent></ram:ApplicableTradeTax>' : '';
        $payment = $withPaymentDetails ? '<ram:SpecifiedTradeSettlementPaymentMeans><ram:TypeCode>58</ram:TypeCode><ram:PayeePartyCreditorFinancialAccount><ram:IBANID>DE89 3704 0044 0532 0130 00</ram:IBANID></ram:PayeePartyCreditorFinancialAccount></ram:SpecifiedTradeSettlementPaymentMeans>'
            .'<ram:SpecifiedTradePaymentTerms><ram:DueDateDateTime><udt:DateTimeString format="102">20261130</udt:DateTimeString></ram:DueDateDateTime></ram:SpecifiedTradePaymentTerms>' : '';

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
              <rsm:ExchangedDocumentContext><ram:GuidelineSpecifiedDocumentContextParameter><ram:ID>urn:cen.eu:en16931:2017</ram:ID></ram:GuidelineSpecifiedDocumentContextParameter></rsm:ExchangedDocumentContext>
              <rsm:ExchangedDocument>
                <ram:ID>RE-2026-0042</ram:ID>
                <ram:TypeCode>{$typeCode}</ram:TypeCode>
                <ram:IssueDateTime><udt:DateTimeString format="102">20261031</udt:DateTimeString></ram:IssueDateTime>
              </rsm:ExchangedDocument>
              <rsm:SupplyChainTradeTransaction>
                <ram:ApplicableHeaderTradeAgreement>
                  <ram:SellerTradeParty>
                    <ram:Name>Voice API GmbH</ram:Name>
                    <ram:PostalTradeAddress><ram:PostcodeCode>10115</ram:PostcodeCode><ram:CityName>Berlin</ram:CityName><ram:CountryID>DE</ram:CountryID></ram:PostalTradeAddress>
                    <ram:SpecifiedTaxRegistration><ram:ID schemeID="FC">12/345/67890</ram:ID></ram:SpecifiedTaxRegistration>
                    <ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">DE123456789</ram:ID></ram:SpecifiedTaxRegistration>
                  </ram:SellerTradeParty>
                  <ram:BuyerTradeParty><ram:Name>Example Customer s.r.o.</ram:Name></ram:BuyerTradeParty>
                </ram:ApplicableHeaderTradeAgreement>
                <ram:ApplicableHeaderTradeDelivery/>
                <ram:ApplicableHeaderTradeSettlement>
                  <ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>
                  {$payment}
                  {$tax}
                  <ram:SpecifiedTradeSettlementHeaderMonetarySummation>
                    <ram:LineTotalAmount>100.00</ram:LineTotalAmount>
                    <ram:TaxBasisTotalAmount>100.00</ram:TaxBasisTotalAmount>
                    <ram:TaxTotalAmount currencyID="EUR">19.00</ram:TaxTotalAmount>
                    <ram:GrandTotalAmount>119.00</ram:GrandTotalAmount>
                    <ram:DuePayableAmount>119.00</ram:DuePayableAmount>
                  </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
                </ram:ApplicableHeaderTradeSettlement>
              </rsm:SupplyChainTradeTransaction>
            </rsm:CrossIndustryInvoice>
            XML;
    }
}
