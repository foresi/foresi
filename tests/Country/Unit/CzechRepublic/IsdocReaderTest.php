<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Unit\CzechRepublic;

use Foresi\Country\Integration\Purchasing\CzechRepublic\IsdocReader;
use Foresi\Purchasing\Contract\Reading\DocumentFile;
use Foresi\Purchasing\Contract\Reading\ReaderFailed;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Contract\Reading\Reading;
use Foresi\Purchasing\Infrastructure\Reading\SmalotPdfFiles;
use Foresi\Tests\Support\IsdocFixtures;
use Foresi\Tests\Support\ReceivedFileFixtures;
use PHPUnit\Framework\TestCase;

final class IsdocReaderTest extends TestCase
{
    private IsdocReader $reader;

    protected function setUp(): void
    {
        $this->reader = new IsdocReader(new SmalotPdfFiles());
    }

    public function testDescribesItself(): void
    {
        self::assertSame('isdoc', $this->reader->source());
        self::assertTrue($this->reader->automatic());
        self::assertTrue($this->reader->supports(new DocumentFile('a.isdoc', 'application/octet-stream', '')));
        self::assertTrue($this->reader->supports(new DocumentFile('a.isdocx', 'application/octet-stream', '')));
        self::assertTrue($this->reader->supports(new DocumentFile('a.xml', 'application/xml', '')));
        self::assertTrue($this->reader->supports(new DocumentFile('a.pdf', 'application/pdf', '')));
        self::assertFalse($this->reader->supports(new DocumentFile('a.png', 'image/png', '')));
    }

    public function testReadsAStandaloneIsdoc(): void
    {
        $reading = $this->reader->read(new DocumentFile('invoice.isdoc', 'application/octet-stream', IsdocFixtures::invoice()));

        self::assertNotNull($reading);
        self::assertSame(
            [
                'kind' => 'tax_invoice',
                'supplier_name' => 'Cloud Host s.r.o.',
                'supplier_registration_number' => '12345678',
                'supplier_vat_id' => 'CZ12345678',
                'supplier_country' => 'CZ',
                'supplier_number' => '20260001',
                'variable_symbol' => '2026100',
                'issue_date' => '2026-10-31',
                'tax_point_date' => '2026-10-30',
                'due_date' => '2026-11-14',
                'currency' => 'CZK',
                'total' => 1_210_000,
                'vat_recap' => [['rate' => '21', 'baseMinor' => 1_000_000, 'vatMinor' => 210_000]],
            ],
            $this->values($reading),
        );
        foreach ($reading->values as $value) {
            self::assertSame('isdoc', $value->source);
        }
        self::assertNull($reading->get(ReadField::Treatment));
    }

    public function testACreditNoteIsReadInPositiveAmounts(): void
    {
        $reading = $this->reader->read(new DocumentFile('credit.isdoc', 'application/xml', IsdocFixtures::creditNote()));

        self::assertSame('credit_note', $reading?->get(ReadField::Kind)?->value);
        self::assertSame(1_210_000, $reading->get(ReadField::Total)?->value);
        self::assertSame([['rate' => '21', 'baseMinor' => 1_000_000, 'vatMinor' => 210_000]], $reading->get(ReadField::VatRecap)?->value);
    }

    public function testReadsIsdocEmbeddedInPdf(): void
    {
        $pdf = ReceivedFileFixtures::pdf(embeddedName: 'invoice.isdoc', embeddedContent: IsdocFixtures::invoice());

        $reading = $this->reader->read(new DocumentFile('invoice.pdf', 'application/pdf', $pdf));

        self::assertSame(1_210_000, $reading?->get(ReadField::Total)?->value);
        self::assertSame('Cloud Host s.r.o.', $reading->get(ReadField::SupplierName)?->value);
    }

    public function testAPdfWithoutAnIsdocFindsNothing(): void
    {
        self::assertNull($this->reader->read(new DocumentFile('a.pdf', 'application/pdf', ReceivedFileFixtures::pdf())));
        $other = ReceivedFileFixtures::pdf(embeddedName: 'factur-x.xml', embeddedContent: '<x/>');
        self::assertNull($this->reader->read(new DocumentFile('b.pdf', 'application/pdf', $other)));
    }

    public function testReadsIsdocx(): void
    {
        $reading = $this->reader->read(new DocumentFile('invoice.isdocx', 'application/octet-stream', $this->isdocx(['invoice.isdoc' => IsdocFixtures::invoice()], 'invoice.isdoc')));

        self::assertSame('20260001', $reading?->get(ReadField::SupplierNumber)?->value);
        self::assertSame(1_210_000, $reading->get(ReadField::Total)?->value);
    }

    public function testAnIsdocxWithoutItsMainDocumentFails(): void
    {
        $this->expectException(ReaderFailed::class);
        $this->reader->read(new DocumentFile('a.isdocx', 'application/octet-stream', $this->isdocx(['other.txt' => 'x'], 'invoice.isdoc')));
    }

    public function testAnIsdocxWithoutAManifestFails(): void
    {
        $this->expectException(ReaderFailed::class);
        $this->reader->read(new DocumentFile('a.isdocx', 'application/octet-stream', $this->isdocx(['invoice.isdoc' => IsdocFixtures::invoice()], null)));
    }

    public function testAnIsdocxThatIsNotAZipFails(): void
    {
        $this->expectException(ReaderFailed::class);
        $this->reader->read(new DocumentFile('a.isdocx', 'application/octet-stream', 'not a zip'));
    }

    public function testAnIsdocxThatInflatesTooMuchFails(): void
    {
        $bomb = $this->isdocx(['invoice.isdoc' => IsdocFixtures::invoice(), 'padding.bin' => str_repeat("\0", 40 * 1024 * 1024)], 'invoice.isdoc');
        self::assertLessThan(1024 * 1024, \strlen($bomb));

        $this->expectException(ReaderFailed::class);
        $this->reader->read(new DocumentFile('a.isdocx', 'application/octet-stream', $bomb));
    }

    public function testAnIsdocxWithTooManyEntriesFails(): void
    {
        $entries = ['invoice.isdoc' => IsdocFixtures::invoice()];
        for ($i = 0; $i < 300; ++$i) {
            $entries[\sprintf('attachment-%d.txt', $i)] = 'x';
        }

        $this->expectException(ReaderFailed::class);
        $this->reader->read(new DocumentFile('a.isdocx', 'application/octet-stream', $this->isdocx($entries, 'invoice.isdoc')));
    }

    public function testRefusesDoctypeAndExternalEntity(): void
    {
        $xml = str_replace(
            '<Invoice ',
            '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><Invoice ',
            IsdocFixtures::invoice(),
        );
        $xml = str_replace('<ID>20260001</ID>', '<ID>&e;</ID>', $xml);
        self::assertStringContainsString('<!DOCTYPE', $xml);

        $this->expectException(ReaderFailed::class);
        $this->reader->read(new DocumentFile('evil.isdoc', 'application/xml', $xml));
    }

    public function testRefusesADoctypeInUtf16(): void
    {
        $xml = mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE x [<!ENTITY e "boom">]><Invoice xmlns="http://isdoc.cz/namespace/2013"><ID>&e;</ID></Invoice>', 'UTF-16LE', 'UTF-8');

        $this->expectException(ReaderFailed::class);
        $this->reader->read(new DocumentFile('evil.isdoc', 'application/xml', "\xFF\xFE".$xml));
    }

    public function testXmlOfAnotherFormatFindsNothing(): void
    {
        self::assertNull($this->reader->read(new DocumentFile('a.xml', 'application/xml', '<?xml version="1.0"?><Other xmlns="urn:example:other"/>')));
    }

    public function testMalformedXmlFails(): void
    {
        $this->expectException(ReaderFailed::class);
        $this->reader->read(new DocumentFile('a.isdoc', 'application/xml', '<Invoice><ID>'));
    }

    public function testABadDateOrAmountDropsOnlyThatValue(): void
    {
        $xml = IsdocFixtures::invoice();
        $xml = str_replace('<IssueDate>2026-10-31</IssueDate>', '<IssueDate>31.10.2026</IssueDate>', $xml);
        $xml = preg_replace('#<TaxPointDate>.*?</TaxPointDate>#', '<TaxPointDate>2026-02-30</TaxPointDate>', $xml);
        $xml = preg_replace('#(<LegalMonetaryTotal>.*?<TaxInclusiveAmount>)[^<]*#s', '${1}12 1oo,00', (string) $xml, 1);

        $reading = $this->reader->read(new DocumentFile('a.isdoc', 'application/xml', (string) $xml));

        self::assertNotNull($reading);
        self::assertNull($reading->get(ReadField::IssueDate));
        self::assertNull($reading->get(ReadField::TaxPointDate));
        self::assertNull($reading->get(ReadField::Total));
        self::assertSame('2026-11-14', $reading->get(ReadField::DueDate)?->value);
        self::assertSame('Cloud Host s.r.o.', $reading->get(ReadField::SupplierName)?->value);
    }

    public function testAForeignCurrencyUsesTheForeignAmounts(): void
    {
        $xml = IsdocFixtures::invoice();
        $xml = str_replace('<LocalCurrencyCode>CZK</LocalCurrencyCode>', '<LocalCurrencyCode>CZK</LocalCurrencyCode><ForeignCurrencyCode>EUR</ForeignCurrencyCode>', $xml);
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('i', 'http://isdoc.cz/namespace/2013');
        $this->addAfter($dom, $xpath, '/i:Invoice/i:LegalMonetaryTotal/i:TaxInclusiveAmount', 'TaxInclusiveAmountCurr', '484.00');
        $this->addAfter($dom, $xpath, '/i:Invoice/i:TaxTotal/i:TaxSubTotal/i:TaxInclusiveAmount', 'TaxableAmountCurr', '400.00');
        $this->addAfter($dom, $xpath, '/i:Invoice/i:TaxTotal/i:TaxSubTotal/i:TaxInclusiveAmount', 'TaxAmountCurr', '84.00');

        $reading = $this->reader->read(new DocumentFile('a.isdoc', 'application/xml', (string) $dom->saveXML()));

        self::assertSame('EUR', $reading?->get(ReadField::Currency)?->value);
        self::assertSame(48_400, $reading->get(ReadField::Total)?->value);
        self::assertSame([['rate' => '21', 'baseMinor' => 40_000, 'vatMinor' => 8_400]], $reading->get(ReadField::VatRecap)?->value);
    }

    private function addAfter(\DOMDocument $dom, \DOMXPath $xpath, string $path, string $name, string $value): void
    {
        $list = $xpath->query($path);
        self::assertInstanceOf(\DOMNodeList::class, $list);
        $node = $list->item(0);
        self::assertInstanceOf(\DOMElement::class, $node);
        $new = $dom->createElementNS('http://isdoc.cz/namespace/2013', $name, $value);
        $node->parentNode?->insertBefore($new, $node->nextSibling);
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Reading $reading): array
    {
        return array_map(static fn (array $entry): mixed => $entry['value'], $reading->toArray());
    }

    /**
     * @param array<string, string> $entries
     */
    private function isdocx(array $entries, ?string $main): string
    {
        $path = tempnam(sys_get_temp_dir(), 'isdocx');
        self::assertNotFalse($path);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        if (null !== $main) {
            $zip->addFromString('manifest.xml', \sprintf('<?xml version="1.0" encoding="UTF-8"?><manifest xmlns="http://isdoc.cz/namespace/manifest"><maindocument filename="%s"/></manifest>', $main));
        }
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}
