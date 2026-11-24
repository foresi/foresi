<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\Purchasing\CzechRepublic;

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
 * Reads an invoice in ISDOC (isdoc.cz): a standalone `.isdoc` file, an `.isdocx`
 * archive (a ZIP whose manifest names the `.isdoc`), or an `.isdoc` file attached
 * to a PDF. It is the most exact reader, so it runs first.
 *
 * A value that is missing or does not fit its format is left out, and the rest of
 * the reading stays. Amounts are positive, also on a credit note, as the standard
 * writes them (appendix A.6); the kind says that it subtracts. The treatment of
 * the VAT is not read here.
 *
 * An archive is never extracted: its entries are read in memory, and its entry
 * count and its inflated size are capped before anything is read.
 */
#[AsTaggedItem(priority: 400)]
final readonly class IsdocReader implements DocumentReader
{
    private const string SOURCE = 'isdoc';
    private const string NAMESPACE = 'http://isdoc.cz/namespace/2013';
    private const string MANIFEST_NAMESPACE = 'http://isdoc.cz/namespace/manifest';
    private const int MAX_ENTRIES = 100;
    private const int MAX_INFLATED_BYTES = 20 * 1024 * 1024;
    private const int MAX_ARCHIVE_BYTES = 10 * 1024 * 1024;
    private const array KINDS = ['1' => 'tax_invoice', '2' => 'credit_note'];

    public function __construct(private PdfFiles $pdfFiles)
    {
    }

    public function source(): string
    {
        return self::SOURCE;
    }

    public function supports(DocumentFile $file): bool
    {
        return $file->isXml() || $file->isPdf() || $this->hasExtension($file, ['isdoc', 'isdocx']);
    }

    public function automatic(): bool
    {
        return true;
    }

    public function read(DocumentFile $file): ?Reading
    {
        $xml = $this->xmlOf($file);

        return null === $xml ? null : $this->readXml($xml);
    }

    /**
     * @throws ReaderFailed
     */
    private function xmlOf(DocumentFile $file): ?string
    {
        if ($file->isPdf()) {
            foreach ($this->pdfFiles->embedded($file) as $embedded) {
                if ($this->hasExtension($embedded, ['isdoc'])) {
                    return $embedded->bytes;
                }
            }

            return null;
        }
        if ($this->hasExtension($file, ['isdocx']) || str_starts_with($file->bytes, 'PK')) {
            return $this->mainDocument($file->bytes);
        }

        return $file->bytes;
    }

    /**
     * @param list<string> $extensions
     */
    private function hasExtension(DocumentFile $file, array $extensions): bool
    {
        return \in_array(strtolower(pathinfo($file->fileName, \PATHINFO_EXTENSION)), $extensions, true);
    }

    /**
     * The `.isdoc` that the manifest of an ISDOCX archive names as its main document.
     *
     * @throws ReaderFailed when the archive is not a ZIP, is over a limit, has no manifest or lacks its main document
     */
    private function mainDocument(string $bytes): string
    {
        if (\strlen($bytes) > self::MAX_ARCHIVE_BYTES) {
            throw new ReaderFailed('The ISDOCX archive is over the size limit.');
        }
        $path = tempnam(sys_get_temp_dir(), 'isdocx');
        if (false === $path) {
            throw new ReaderFailed('The ISDOCX archive cannot be opened.');
        }
        try {
            file_put_contents($path, $bytes);
            $zip = new \ZipArchive();
            if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
                throw new ReaderFailed('The file is not a ZIP archive.');
            }
            try {
                $this->checkArchive($zip);
                $manifest = SafeXml::load($this->entry($zip, 'manifest.xml', SafeXml::MAX_BYTES));
                $name = $this->mainDocumentName($manifest);

                return $this->entry($zip, $name, SafeXml::MAX_BYTES);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * @throws ReaderFailed
     */
    private function checkArchive(\ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new ReaderFailed('The ISDOCX archive has too many entries.');
        }
        $inflated = 0;
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index);
            if (false === $stat) {
                throw new ReaderFailed('The ISDOCX archive is damaged.');
            }
            $inflated += $stat['size'];
            if ($inflated > self::MAX_INFLATED_BYTES) {
                throw new ReaderFailed('The ISDOCX archive inflates over the limit.');
            }
        }
    }

    /**
     * @throws ReaderFailed
     */
    private function mainDocumentName(\DOMDocument $manifest): string
    {
        $xpath = new \DOMXPath($manifest);
        $xpath->registerNamespace('m', self::MANIFEST_NAMESPACE);
        $name = $xpath->evaluate('string(/m:manifest/m:maindocument/@filename)');

        if (!\is_string($name) || '' === trim($name)) {
            throw new ReaderFailed('The manifest of the ISDOCX archive names no main document.');
        }

        return trim($name);
    }

    /**
     * Reads one entry into memory, by name only (a name from the manifest is never a path on disk).
     *
     * @throws ReaderFailed
     */
    private function entry(\ZipArchive $zip, string $name, int $max): string
    {
        $stream = $zip->getStream($name);
        if (false === $stream) {
            throw new ReaderFailed('The ISDOCX archive lacks a file it names.');
        }
        $content = stream_get_contents($stream, $max + 1);
        fclose($stream);
        if (false === $content || \strlen($content) > $max) {
            throw new ReaderFailed('A file of the ISDOCX archive is over the size limit.');
        }

        return $content;
    }

    /**
     * @throws ReaderFailed when the XML is not safe or not well-formed
     */
    private function readXml(string $xml): ?Reading
    {
        $document = SafeXml::load($xml);
        $root = $document->documentElement;
        if (!$root instanceof \DOMElement || 'Invoice' !== $root->localName || self::NAMESPACE !== $root->namespaceURI) {
            return null;
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('i', self::NAMESPACE);

        $foreign = $this->text($xpath, '/i:Invoice/i:ForeignCurrencyCode');
        $foreign = null !== $foreign && 1 === preg_match('/^[A-Z]{3}$/D', $foreign) ? $foreign : null;
        // Some generators write the local currency as the foreign one; its amounts are the plain ones.
        if ($foreign === $this->text($xpath, '/i:Invoice/i:LocalCurrencyCode')) {
            $foreign = null;
        }
        $suffix = null === $foreign ? '' : 'Curr';
        $currency = $foreign ?? $this->text($xpath, '/i:Invoice/i:LocalCurrencyCode');
        $supplier = '/i:Invoice/i:AccountingSupplierParty/i:Party';
        $type = $this->text($xpath, '/i:Invoice/i:DocumentType');
        $details = '/i:Invoice/i:PaymentMeans/i:Payment/i:Details';
        $total = $this->text($xpath, '/i:Invoice/i:LegalMonetaryTotal/i:TaxInclusiveAmount'.$suffix);
        $country = $this->text($xpath, $supplier.'/i:PostalAddress/i:Country/i:IdentificationCode');
        $symbol = $this->text($xpath, $details.'/i:VariableSymbol');
        $vatId = $this->text($xpath, $supplier.'/i:PartyTaxScheme[i:TaxScheme="VAT"]/i:CompanyID') ?? $this->text($xpath, $supplier.'/i:PartyTaxScheme/i:CompanyID');
        $registration = $this->text($xpath, $supplier.'/i:PartyIdentification/i:ID');
        $name = $this->text($xpath, $supplier.'/i:PartyName/i:Name');
        $number = $this->text($xpath, '/i:Invoice/i:ID');

        $values = [
            $this->value(ReadField::Kind, null === $type ? null : (self::KINDS[$type] ?? null)),
            $this->value(ReadField::SupplierName, null === $name ? null : ReadFormat::text($name, 255)),
            $this->value(ReadField::SupplierRegistrationNumber, null === $registration ? null : ReadFormat::text($registration, 64)),
            $this->value(ReadField::SupplierVatId, null === $vatId ? null : ReadFormat::vatId($vatId)),
            $this->value(ReadField::SupplierCountry, null !== $country && 1 === preg_match('/^[A-Za-z]{2}$/D', $country) ? strtoupper($country) : null),
            $this->value(ReadField::SupplierNumber, null === $number ? null : ReadFormat::text($number, 64)),
            $this->value(ReadField::VariableSymbol, null !== $symbol && 1 === preg_match('/^\d{1,10}$/D', $symbol) ? $symbol : null),
            $this->value(ReadField::IssueDate, $this->date($xpath, '/i:Invoice/i:IssueDate')),
            $this->value(ReadField::TaxPointDate, $this->date($xpath, '/i:Invoice/i:TaxPointDate')),
            $this->value(ReadField::DueDate, $this->date($xpath, $details.'/i:PaymentDueDate')),
            $this->value(ReadField::Currency, null !== $currency && 1 === preg_match('/^[A-Z]{3}$/D', $currency) ? $currency : null),
            $this->value(ReadField::Total, null === $total ? null : ReadFormat::minor($total)),
            $this->value(ReadField::VatRecap, $this->recap($xpath, $suffix)),
        ];
        $reading = new Reading(array_filter($values));

        return $reading->isEmpty() ? null : $reading;
    }

    /**
     * One row per rate, the subtotals of the same rate added up. A row that cannot be
     * read leaves no recap at all, because a recap with a row missing would not add up.
     *
     * @return list<array{rate: string, baseMinor: int, vatMinor: int}>|null
     */
    private function recap(\DOMXPath $xpath, string $suffix): ?array
    {
        $rows = [];
        $nodes = $xpath->query('/i:Invoice/i:TaxTotal/i:TaxSubTotal');
        foreach ($nodes instanceof \DOMNodeList ? $nodes : [] as $subtotal) {
            if (!$subtotal instanceof \DOMElement) {
                continue;
            }
            $rate = $this->text($xpath, 'i:TaxCategory/i:Percent', $subtotal);
            $base = $this->text($xpath, 'i:TaxableAmount'.$suffix, $subtotal);
            $vat = $this->text($xpath, 'i:TaxAmount'.$suffix, $subtotal);
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

    private function date(\DOMXPath $xpath, string $path): ?string
    {
        $text = $this->text($xpath, $path);

        return null === $text ? null : ReadFormat::date($text);
    }

    /**
     * The trimmed text of the first node at the path; null when there is none or it is empty.
     */
    private function text(\DOMXPath $xpath, string $path, ?\DOMNode $context = null): ?string
    {
        $list = $xpath->query($path, $context);
        $node = $list instanceof \DOMNodeList ? $list->item(0) : null;
        $text = $node instanceof \DOMElement ? trim($node->textContent) : '';

        return '' === $text ? null : $text;
    }
}
