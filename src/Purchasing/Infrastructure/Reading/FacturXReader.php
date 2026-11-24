<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Reading;

use Foresi\Purchasing\Contract\Reading\DocumentFile;
use Foresi\Purchasing\Contract\Reading\DocumentReader;
use Foresi\Purchasing\Contract\Reading\PdfFiles;
use Foresi\Purchasing\Contract\Reading\ReaderFailed;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Contract\Reading\ReadFormat;
use Foresi\Purchasing\Contract\Reading\Reading;
use Foresi\Purchasing\Contract\Reading\ReadValue;
use Foresi\Purchasing\Contract\Reading\SafeXml;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Reads an invoice in the Cross Industry Invoice format of EN 16931: the XML
 * attached to a Factur-X or ZUGFeRD PDF (`factur-x.xml`, `zugferd-invoice.xml`,
 * `xrechnung.xml`), or the XML alone. The MINIMUM and BASIC WL profiles have no
 * tax rows, so they give no VAT recap.
 *
 * A value that is missing or does not fit its format is left out, and the rest
 * of the reading stays. Amounts are positive, also on a credit note; the kind
 * says that it subtracts. The treatment of the VAT is not read here.
 */
#[AsTaggedItem(priority: 300)]
final readonly class FacturXReader implements DocumentReader
{
    private const string SOURCE = 'factur_x';
    private const string RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    private const string RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    private const string UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';
    private const array ATTACHMENTS = ['factur-x.xml', 'zugferd-invoice.xml', 'xrechnung.xml'];
    private const array KINDS = ['380' => 'tax_invoice', '381' => 'credit_note'];
    private const string SETTLEMENT = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement';
    private const string SELLER = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty';

    public function __construct(private PdfFiles $pdfFiles)
    {
    }

    public function source(): string
    {
        return self::SOURCE;
    }

    public function supports(DocumentFile $file): bool
    {
        return $file->isXml() || $file->isPdf();
    }

    public function automatic(): bool
    {
        return true;
    }

    public function read(DocumentFile $file): ?Reading
    {
        if (!$file->isPdf()) {
            return $this->readXml($file->bytes);
        }
        foreach ($this->pdfFiles->embedded($file) as $embedded) {
            if (\in_array(strtolower($embedded->fileName), self::ATTACHMENTS, true)) {
                $reading = $this->readXml($embedded->bytes);
                if ($reading instanceof Reading) {
                    return $reading;
                }
            }
        }

        return null;
    }

    /**
     * @throws ReaderFailed when the XML is not safe or not well-formed
     */
    private function readXml(string $xml): ?Reading
    {
        $document = SafeXml::load($xml);
        $root = $document->documentElement;
        if (!$root instanceof \DOMElement || 'CrossIndustryInvoice' !== $root->localName || self::RSM !== $root->namespaceURI) {
            return null;
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('rsm', self::RSM);
        $xpath->registerNamespace('ram', self::RAM);
        $xpath->registerNamespace('udt', self::UDT);

        $type = $this->text($xpath, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode');
        $number = $this->text($xpath, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID');
        $name = $this->text($xpath, self::SELLER.'/ram:Name');
        $vatId = $this->text($xpath, self::SELLER."/ram:SpecifiedTaxRegistration/ram:ID[@schemeID='VA']");
        $country = $this->text($xpath, self::SELLER.'/ram:PostalTradeAddress/ram:CountryID');
        $currency = $this->text($xpath, self::SETTLEMENT.'/ram:InvoiceCurrencyCode');
        $total = $this->text($xpath, self::SETTLEMENT.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:GrandTotalAmount');
        $iban = $this->text($xpath, self::SETTLEMENT.'/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:IBANID');
        $iban = null === $iban ? null : strtoupper((string) preg_replace('/\s+/', '', $iban));

        $values = [
            $this->value(ReadField::Kind, null === $type ? null : (self::KINDS[$type] ?? null)),
            $this->value(ReadField::SupplierName, null === $name ? null : ReadFormat::text($name, 255)),
            $this->value(ReadField::SupplierVatId, null === $vatId ? null : ReadFormat::vatId($vatId)),
            $this->value(ReadField::SupplierCountry, null !== $country && 1 === preg_match('/^[A-Za-z]{2}$/D', $country) ? strtoupper($country) : null),
            $this->value(ReadField::SupplierNumber, null === $number ? null : ReadFormat::text($number, 64)),
            $this->value(ReadField::IssueDate, $this->date($xpath, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString')),
            $this->value(ReadField::DueDate, $this->date($xpath, self::SETTLEMENT.'/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString')),
            $this->value(ReadField::Currency, null !== $currency && 1 === preg_match('/^[A-Z]{3}$/D', $currency) ? $currency : null),
            $this->value(ReadField::Total, null === $total ? null : ReadFormat::minor($total)),
            $this->value(ReadField::VatRecap, $this->recap($xpath)),
            $this->value(ReadField::BankAccount, null !== $iban && 1 === preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D', $iban) ? $iban : null),
        ];
        $reading = new Reading(array_filter($values));

        return $reading->isEmpty() ? null : $reading;
    }

    /**
     * One row per rate, the rows of the same rate added up. A row that cannot be
     * read leaves no recap at all, because a recap with a row missing would not add up.
     * The profiles without tax rows have none.
     *
     * @return list<array{rate: string, baseMinor: int, vatMinor: int}>|null
     */
    private function recap(\DOMXPath $xpath): ?array
    {
        $rows = [];
        $nodes = $xpath->query(self::SETTLEMENT.'/ram:ApplicableTradeTax');
        foreach ($nodes instanceof \DOMNodeList ? $nodes : [] as $tax) {
            if (!$tax instanceof \DOMElement) {
                continue;
            }
            $rate = $this->text($xpath, 'ram:RateApplicablePercent', $tax);
            $base = $this->text($xpath, 'ram:BasisAmount', $tax);
            $vat = $this->text($xpath, 'ram:CalculatedAmount', $tax);
            $rate = null === $rate ? null : ReadFormat::rate($rate);
            $base = null === $base ? null : ReadFormat::minor($base);
            $vat = null === $vat ? null : ReadFormat::minor($vat);
            if (in_array(null, [$rate, $base, $vat], true)) {
                return null;
            }
            $row = $rows[$rate] ?? ['rate' => $rate, 'baseMinor' => 0, 'vatMinor' => 0];
            $row['baseMinor'] += $base;
            $row['vatMinor'] += $vat;
            $rows[$rate] = $row;
        }

        return [] === $rows ? null : array_values($rows);
    }

    /**
     * @param string|int|array<mixed>|null $value
     */
    private function value(ReadField $field, string|int|array|null $value): ?ReadValue
    {
        return null === $value ? null : new ReadValue($field, $value, self::SOURCE);
    }

    /**
     * The date of a `DateTimeString`: format 102 (`YYYYMMDD`), or no format at all.
     */
    private function date(\DOMXPath $xpath, string $path): ?string
    {
        $list = $xpath->query($path);
        $node = $list instanceof \DOMNodeList ? $list->item(0) : null;
        if (!$node instanceof \DOMElement) {
            return null;
        }
        $format = $node->getAttribute('format');
        $text = trim($node->textContent);

        return ('' === $format || '102' === $format) && 1 === preg_match('/^\d{8}$/D', $text) ? ReadFormat::date($text) : null;
    }

    private function text(\DOMXPath $xpath, string $path, ?\DOMNode $context = null): ?string
    {
        $list = $xpath->query($path, $context);
        $node = $list instanceof \DOMNodeList ? $list->item(0) : null;
        $text = $node instanceof \DOMElement ? trim($node->textContent) : '';

        return '' === $text ? null : $text;
    }
}
