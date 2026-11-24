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
use Foresi\Purchasing\Contract\Reading\SafeXml;
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

    public function testTheExtensionAloneMakesAFileSupportedWhateverItsMediaType(): void
    {
        self::assertTrue($this->reader->supports(new DocumentFile('a.isdoc', 'text/plain', '')));
        self::assertTrue($this->reader->supports(new DocumentFile('a.isdocx', 'text/plain', '')));
        self::assertTrue($this->reader->supports(new DocumentFile('A.ISDOCX', 'text/plain', '')));
        self::assertFalse($this->reader->supports(new DocumentFile('a.txt', 'text/plain', '')));
    }

    public function testAnIsdocxIsOpenedAsAnArchiveByItsNameOrByItsBytes(): void
    {
        $isdocx = $this->isdocx(['invoice.isdoc' => IsdocFixtures::invoice()], 'invoice.isdoc');

        // By its bytes, whatever the name.
        self::assertSame('20260001', $this->reader->read(new DocumentFile('invoice.isdoc', 'application/octet-stream', $isdocx))?->get(ReadField::SupplierNumber)?->value);
        // By its name, whatever the bytes: a plain XML under the name of an archive is no archive.
        try {
            $this->reader->read(new DocumentFile('invoice.isdocx', 'application/octet-stream', IsdocFixtures::invoice()));
            self::fail('Expected a refusal.');
        } catch (ReaderFailed $e) {
            self::assertSame('The file is not a ZIP archive.', $e->getMessage());
        }
        // The extension is compared in lower case.
        self::assertNotNull($this->reader->read(new DocumentFile('INVOICE.ISDOCX', 'application/octet-stream', $isdocx)));
        try {
            $this->reader->read(new DocumentFile('INVOICE.ISDOCX', 'application/octet-stream', IsdocFixtures::invoice()));
            self::fail('Expected a refusal.');
        } catch (ReaderFailed $e) {
            self::assertSame('The file is not a ZIP archive.', $e->getMessage());
        }
    }

    public function testTheSizeLimitOfAnArchiveIsInclusive(): void
    {
        $limit = 10 * 1024 * 1024;

        $this->assertRefused('The file is not a ZIP archive.', new DocumentFile('a.isdocx', 'application/octet-stream', 'PK'.str_repeat("\0", $limit - 2)));
        $this->assertRefused('The ISDOCX archive is over the size limit.', new DocumentFile('a.isdocx', 'application/octet-stream', 'PK'.str_repeat("\0", $limit - 1)));
    }

    public function testNoTemporaryFileIsLeftBehind(): void
    {
        $isdocx = $this->isdocx(['invoice.isdoc' => IsdocFixtures::invoice()], 'invoice.isdoc');
        $before = $this->temporaryFiles();

        $this->reader->read(new DocumentFile('a.isdocx', 'application/octet-stream', $isdocx));
        $this->assertNoNewTemporaryFile($before);

        $this->assertRefused('The file is not a ZIP archive.', new DocumentFile('a.isdocx', 'application/octet-stream', 'PKnot a zip'));
        $this->assertNoNewTemporaryFile($before);

        $this->assertRefused('The ISDOCX archive lacks a file it names.', new DocumentFile('a.isdocx', 'application/octet-stream', $this->isdocx(['other.txt' => 'x'], 'invoice.isdoc')));
        $this->assertNoNewTemporaryFile($before);
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

    public function testAnArchiveMayHoldAsManyEntriesAsTheLimitAndNoMore(): void
    {
        // The manifest, the invoice and the rest: a hundred entries in all.
        $entries = ['invoice.isdoc' => IsdocFixtures::invoice()];
        for ($i = 0; $i < 98; ++$i) {
            $entries[\sprintf('attachment-%d.txt', $i)] = 'x';
        }
        self::assertNotNull($this->reader->read(new DocumentFile('a.isdocx', 'application/octet-stream', $this->isdocx($entries, 'invoice.isdoc'))));

        $entries['one-more.txt'] = 'x';
        $this->assertRefused('The ISDOCX archive has too many entries.', new DocumentFile('a.isdocx', 'application/octet-stream', $this->isdocx($entries, 'invoice.isdoc')));
    }

    public function testTheInflatedSizeOfAnArchiveIsCappedInclusively(): void
    {
        $limit = 20 * 1024 * 1024;
        $invoice = IsdocFixtures::invoice();
        $used = \strlen($this->manifest('invoice.isdoc')) + \strlen($invoice);

        $atLimit = $this->isdocx(['invoice.isdoc' => $invoice, 'padding.bin' => str_repeat("\0", $limit - $used)], 'invoice.isdoc');
        self::assertNotNull($this->reader->read(new DocumentFile('a.isdocx', 'application/octet-stream', $atLimit)));

        $over = $this->isdocx(['invoice.isdoc' => $invoice, 'padding.bin' => str_repeat("\0", $limit - $used + 1)], 'invoice.isdoc');
        $this->assertRefused('The ISDOCX archive inflates over the limit.', new DocumentFile('a.isdocx', 'application/octet-stream', $over));
    }

    public function testTheInflatedSizesOfTheEntriesAreAddedUp(): void
    {
        $each = str_repeat("\0", 12 * 1024 * 1024);
        $entries = ['invoice.isdoc' => IsdocFixtures::invoice(), 'first.bin' => $each, 'second.bin' => $each];

        $this->assertRefused('The ISDOCX archive inflates over the limit.', new DocumentFile('a.isdocx', 'application/octet-stream', $this->isdocx($entries, 'invoice.isdoc')));
    }

    public function testTheNameOfTheMainDocumentIsTrimmed(): void
    {
        $isdocx = $this->isdocx(['invoice.isdoc' => IsdocFixtures::invoice()], null, $this->manifest("  invoice.isdoc \n"));

        self::assertSame('20260001', $this->reader->read(new DocumentFile('a.isdocx', 'application/octet-stream', $isdocx))?->get(ReadField::SupplierNumber)?->value);
    }

    public function testAManifestWithoutAnUsableNameIsRefusedAsSuch(): void
    {
        foreach (['', '   ', "\n"] as $name) {
            $isdocx = $this->isdocx(['invoice.isdoc' => IsdocFixtures::invoice()], null, $this->manifest($name));
            $this->assertRefused('The manifest of the ISDOCX archive names no main document.', new DocumentFile('a.isdocx', 'application/octet-stream', $isdocx));
        }
        $noElement = $this->isdocx(['invoice.isdoc' => IsdocFixtures::invoice()], null, '<?xml version="1.0"?><manifest xmlns="http://isdoc.cz/namespace/manifest"/>');
        $this->assertRefused('The manifest of the ISDOCX archive names no main document.', new DocumentFile('a.isdocx', 'application/octet-stream', $noElement));
    }

    public function testAFileOfTheArchiveMayFillTheLimitButNotExceedIt(): void
    {
        $limit = SafeXml::MAX_BYTES;
        // The padding is a comment before the root, so that the closing tag still ends the file.
        $invoice = str_replace('<Invoice ', '<!--  --><Invoice ', IsdocFixtures::invoice());
        $padded = str_replace('<!--  -->', '<!--'.str_repeat('x', $limit - \strlen($invoice) + 2).'-->', $invoice);
        self::assertSame($limit, \strlen($padded));

        $reading = $this->reader->read(new DocumentFile('a.isdocx', 'application/octet-stream', $this->isdocx(['invoice.isdoc' => $padded], 'invoice.isdoc')));
        self::assertSame('20260001', $reading?->get(ReadField::SupplierNumber)?->value);

        $this->assertRefused('A file of the ISDOCX archive is over the size limit.', new DocumentFile('a.isdocx', 'application/octet-stream', $this->isdocx(['invoice.isdoc' => $padded.' '], 'invoice.isdoc')));
        $this->assertRefused('A file of the ISDOCX archive is over the size limit.', new DocumentFile('a.isdocx', 'application/octet-stream', $this->isdocx(['invoice.isdoc' => $padded.str_repeat(' ', 5)], 'invoice.isdoc')));
    }

    public function testTextsAreTrimmedAndCappedAtTheirColumns(): void
    {
        $xml = IsdocFixtures::invoice();
        $xml = str_replace('<Name>Cloud Host s.r.o.</Name>', '<Name>  '.str_repeat('n', 256).'  </Name>', $xml);
        $xml = str_replace('<ID>12345678</ID>', '<ID>'.str_repeat('1', 65).'</ID>', $xml);
        $xml = str_replace('<ID>20260001</ID>', '<ID>'.str_repeat('2', 65).'</ID>', $xml);

        $reading = $this->reader->read(new DocumentFile('a.isdoc', 'application/xml', $xml));

        self::assertSame(str_repeat('n', 255), $reading?->get(ReadField::SupplierName)?->value);
        self::assertSame(str_repeat('1', 64), $reading->get(ReadField::SupplierRegistrationNumber)?->value);
        self::assertSame(str_repeat('2', 64), $reading->get(ReadField::SupplierNumber)?->value);
    }

    public function testTextsOfExactlyTheirColumnsAreKept(): void
    {
        $xml = IsdocFixtures::invoice();
        $xml = str_replace('<Name>Cloud Host s.r.o.</Name>', '<Name>'.str_repeat('n', 255).'</Name>', $xml);
        $xml = str_replace('<ID>12345678</ID>', '<ID>'.str_repeat('1', 64).'</ID>', $xml);
        $xml = str_replace('<ID>20260001</ID>', '<ID>'.str_repeat('2', 64).'</ID>', $xml);

        $reading = $this->reader->read(new DocumentFile('a.isdoc', 'application/xml', $xml));

        self::assertSame(str_repeat('n', 255), $reading?->get(ReadField::SupplierName)?->value);
        self::assertSame(str_repeat('1', 64), $reading->get(ReadField::SupplierRegistrationNumber)?->value);
        self::assertSame(str_repeat('2', 64), $reading->get(ReadField::SupplierNumber)?->value);
    }

    public function testTheSupplierCountryIsTwoLettersInCapitals(): void
    {
        self::assertSame('DE', $this->read($this->withCountry('de'))->get(ReadField::SupplierCountry)?->value);
        self::assertNull($this->read($this->withCountry('CZE'))->get(ReadField::SupplierCountry));
        self::assertNull($this->read($this->withCountry('1C'))->get(ReadField::SupplierCountry));
        self::assertNull($this->read($this->withCountry('C'))->get(ReadField::SupplierCountry));
        // The rest of the reading stays.
        self::assertSame('Cloud Host s.r.o.', $this->read($this->withCountry('CZE'))->get(ReadField::SupplierName)?->value);
    }

    public function testTheVariableSymbolIsOneToTenDigits(): void
    {
        self::assertSame('1234567890', $this->read($this->withSymbol('1234567890'))->get(ReadField::VariableSymbol)?->value);
        self::assertSame('7', $this->read($this->withSymbol('7'))->get(ReadField::VariableSymbol)?->value);
        self::assertNull($this->read($this->withSymbol('12345678901'))->get(ReadField::VariableSymbol));
        self::assertNull($this->read($this->withSymbol('X123'))->get(ReadField::VariableSymbol));
        self::assertNull($this->read($this->withSymbol('123X'))->get(ReadField::VariableSymbol));
    }

    public function testTheCurrencyIsThreeLettersInCapitals(): void
    {
        self::assertSame('CZK', $this->read(IsdocFixtures::invoice())->get(ReadField::Currency)?->value);
        self::assertNull($this->read($this->withLocalCurrency('CZKX'))->get(ReadField::Currency));
        self::assertNull($this->read($this->withLocalCurrency('1CZK'))->get(ReadField::Currency));
        self::assertNull($this->read($this->withLocalCurrency('czk'))->get(ReadField::Currency));
        // The rest of the reading stays.
        self::assertSame(1_210_000, $this->read($this->withLocalCurrency('CZKX'))->get(ReadField::Total)?->value);
    }

    public function testAForeignCurrencyThatIsNotThreeCapitalsIsNotOne(): void
    {
        foreach (['EURO', '1EUR', 'eur'] as $foreign) {
            $xml = str_replace('<LocalCurrencyCode>CZK</LocalCurrencyCode>', '<LocalCurrencyCode>CZK</LocalCurrencyCode><ForeignCurrencyCode>'.$foreign.'</ForeignCurrencyCode>', IsdocFixtures::invoice());

            $reading = $this->read($xml);

            self::assertSame('CZK', $reading->get(ReadField::Currency)?->value, $foreign);
            self::assertSame(1_210_000, $reading->get(ReadField::Total)?->value, $foreign);
        }
    }

    public function testTheVatIdIsTheOneOfTheVatScheme(): void
    {
        $xml = str_replace(
            '<PartyTaxScheme><CompanyID>CZ12345678</CompanyID><TaxScheme>VAT</TaxScheme></PartyTaxScheme>',
            '<PartyTaxScheme><CompanyID>CZ99999999</CompanyID><TaxScheme>TIN</TaxScheme></PartyTaxScheme><PartyTaxScheme><CompanyID>CZ12345678</CompanyID><TaxScheme>VAT</TaxScheme></PartyTaxScheme>',
            $this->compact(IsdocFixtures::invoice()),
        );
        self::assertStringContainsString('CZ99999999', $xml);

        self::assertSame('CZ12345678', $this->read($xml)->get(ReadField::SupplierVatId)?->value);
    }

    public function testWithoutAVatSchemeTheFirstSchemeSuppliesTheVatId(): void
    {
        $xml = str_replace(
            '<PartyTaxScheme><CompanyID>CZ12345678</CompanyID><TaxScheme>VAT</TaxScheme></PartyTaxScheme>',
            '<PartyTaxScheme><CompanyID>CZ99999999</CompanyID><TaxScheme>TIN</TaxScheme></PartyTaxScheme>',
            $this->compact(IsdocFixtures::invoice()),
        );
        self::assertStringContainsString('CZ99999999', $xml);

        self::assertSame('CZ99999999', $this->read($xml)->get(ReadField::SupplierVatId)?->value);
    }

    public function testSubtotalsOfTheSameRateAreAddedUp(): void
    {
        $xml = $this->compact(IsdocFixtures::invoice());
        self::assertSame(1, preg_match('#<TaxSubTotal>.*?</TaxSubTotal>#s', $xml, $found));
        $second = str_replace(['10000.00', '2100.00', '12100.00'], ['500.50', '105.10', '605.60'], $found[0]);
        $reduced = str_replace(['10000.00', '2100.00', '12100.00', '21.00'], ['200.00', '20.00', '220.00', '10.00'], $found[0]);
        $xml = str_replace($found[0], $found[0].$second.$reduced, $xml);

        self::assertSame(
            [
                ['rate' => '21', 'baseMinor' => 1_050_050, 'vatMinor' => 220_510],
                ['rate' => '10', 'baseMinor' => 20_000, 'vatMinor' => 2_000],
            ],
            $this->read($xml)->get(ReadField::VatRecap)?->value,
        );
    }

    public function testATextIsTrimmed(): void
    {
        $xml = str_replace('<ID>20260001</ID>', '<ID>  20260001 </ID>', IsdocFixtures::invoice());
        $xml = str_replace('<VariableSymbol>2026100</VariableSymbol>', '<VariableSymbol>   </VariableSymbol>', $xml);

        $reading = $this->read($xml);

        self::assertSame('20260001', $reading->get(ReadField::SupplierNumber)?->value);
        self::assertNull($reading->get(ReadField::VariableSymbol));
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

    public function testAForeignCurrencyEqualToTheLocalOneUsesThePlainAmounts(): void
    {
        $xml = str_replace('<LocalCurrencyCode>CZK</LocalCurrencyCode>', '<LocalCurrencyCode>CZK</LocalCurrencyCode><ForeignCurrencyCode>CZK</ForeignCurrencyCode>', IsdocFixtures::invoice());

        $reading = $this->reader->read(new DocumentFile('a.isdoc', 'application/xml', $xml));

        self::assertSame('CZK', $reading?->get(ReadField::Currency)?->value);
        self::assertSame(1_210_000, $reading->get(ReadField::Total)?->value);
        self::assertSame([['rate' => '21', 'baseMinor' => 1_000_000, 'vatMinor' => 210_000]], $reading->get(ReadField::VatRecap)?->value);
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

    private function read(string $xml): Reading
    {
        $reading = $this->reader->read(new DocumentFile('a.isdoc', 'application/xml', $xml));
        self::assertNotNull($reading);

        return $reading;
    }

    private function compact(string $xml): string
    {
        return (string) preg_replace('#>\s+<#', '><', $xml);
    }

    private function withCountry(string $code): string
    {
        return $this->withReplaced('<IdentificationCode>CZ</IdentificationCode>', '<IdentificationCode>'.$code.'</IdentificationCode>', 1);
    }

    private function withSymbol(string $symbol): string
    {
        return str_replace('<VariableSymbol>2026100</VariableSymbol>', '<VariableSymbol>'.$symbol.'</VariableSymbol>', IsdocFixtures::invoice());
    }

    private function withLocalCurrency(string $code): string
    {
        return str_replace('<LocalCurrencyCode>CZK</LocalCurrencyCode>', '<LocalCurrencyCode>'.$code.'</LocalCurrencyCode>', IsdocFixtures::invoice());
    }

    /**
     * The invoice with its first $times matches of $search replaced.
     */
    private function withReplaced(string $search, string $replace, int $times): string
    {
        return (string) preg_replace('#'.preg_quote($search, '#').'#', $replace, IsdocFixtures::invoice(), $times);
    }

    private function assertRefused(string $message, DocumentFile $file): void
    {
        try {
            $this->reader->read($file);
        } catch (ReaderFailed $e) {
            self::assertSame($message, $e->getMessage());

            return;
        }
        self::fail('Expected a refusal: '.$message);
    }

    /**
     * @param list<string> $before
     */
    private function assertNoNewTemporaryFile(array $before): void
    {
        // Another test process may hold a file of its own for a moment; one that was left behind never goes.
        $new = [];
        for ($try = 0; $try < 20; ++$try) {
            $new = array_diff($this->temporaryFiles(), $before);
            if ([] === $new) {
                break;
            }
            usleep(50_000);
        }
        self::assertSame([], array_values($new));
    }

    /**
     * @return list<string>
     */
    private function temporaryFiles(): array
    {
        $found = glob(sys_get_temp_dir().'/isdocx*');

        return false === $found ? [] : $found;
    }

    private function manifest(string $name): string
    {
        return \sprintf('<?xml version="1.0" encoding="UTF-8"?><manifest xmlns="http://isdoc.cz/namespace/manifest"><maindocument filename="%s"/></manifest>', $name);
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
    private function isdocx(array $entries, ?string $main, ?string $manifest = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zipfix');
        self::assertNotFalse($path);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        if (null !== $manifest) {
            $zip->addFromString('manifest.xml', $manifest);
        } elseif (null !== $main) {
            $zip->addFromString('manifest.xml', $this->manifest($main));
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
