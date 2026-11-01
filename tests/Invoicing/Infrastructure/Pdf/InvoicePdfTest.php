<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Pdf;

use App\Invoicing\Application\InvoicePdf;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\IssuedDocument;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\RenderedPdf;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Infrastructure\Form\AmountText;
use App\Invoicing\Infrastructure\Pdf\TcpdfInvoicePdf;
use App\Party\Application\FirmImages;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use App\Tests\Invoicing\Application\InvoiceTestCase;
use App\Tests\Support\IsdocSchema;
use App\Tests\Support\PdfTools;
use App\Tests\Support\VeraPdf;
use chillerlan\QRCode\QRCode;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;

final class InvoicePdfTest extends InvoiceTestCase
{
    /** The white plate of the panel on the first page, in pixels, inside its edges. */
    private const int PLATE_LEFT = 1225;
    private const int PLATE_RIGHT = 1610;
    private const int PLATE_TOP = 755;
    private const int PLATE_BOTTOM = 1125;

    private InvoicePdf $pdf;
    private string $tenantImages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdf = self::getContainer()->get(InvoicePdf::class);
        $root = self::getContainer()->getParameter('app.firm_image_dir');
        $this->tenantImages = $root.'/'.self::getContainer()->get(TenantContext::class)->current()->toString();
    }

    protected function tearDown(): void
    {
        $files = glob($this->tenantImages.'/*');
        array_map(unlink(...), false === $files ? [] : $files);
        @rmdir($this->tenantImages);
        parent::tearDown();
    }

    public function testAPayerPdfHasEveryMandatoryField(): void
    {
        $document = $this->issued([$this->line('1260.00', text: 'Consulting work')], ['orderNumber' => 'PO-77']);
        $text = $this->textOf($document);

        foreach ([
            'Faktura - daňový doklad',
            $document->number,
            'Example Firm s.r.o.', 'Example Street 1', '100 00', 'Example City', 'IČO: 12345695', 'DIČ: CZ12345695', 'Example register, entry C 1',
            'Example Customer a.s.', 'Customer Street 2', '200 00', 'Customer City', 'IČO: 87654320', 'DIČ: CZ87654320',
            'Datum vystavení', '1. 11. 2026',
            'Datum zdanitelného plnění',
            'Datum splatnosti', '15. 11. 2026',
            'Consulting work', 'Cena za m. j.', 'bez DPH', 'Sazba', 'Základ', 'DPH',
            '1 260,00', '264,60', '1 524,60',
            '1 525,00 CZK',
            'CZ6508000000192000145399', '19-2000145399/0800',
            'Variabilní symbol', '20260001', 'Konstantní symbol', '0308',
            'PO-77', 'Before', 'After', 'Consulting',
            'Razítko a podpis',
            'QR Platba+F',
        ] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
    }

    public function testAnEnglishDocumentIsInEnglish(): void
    {
        $text = $this->textOf($this->issued([$this->line()], ['language' => DocumentLanguage::English]));

        foreach (['Invoice - tax document', 'Tax point date', 'Due date', 'Stamp and signature', 'VAT ID: CZ12345695', '1 Nov 2026'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
        self::assertStringNotContainsString('Faktura', $text);
    }

    public function testAPriceWithVatStillPrintsTheUnitPriceWithoutVat(): void
    {
        $text = $this->textOf($this->issued([$this->line('1210.00', PriceType::WithVat)]));

        self::assertMatchesRegularExpression('/1,00\s+h\s+1 000,00/', $text);
    }

    /**
     * The stored unit net is after the discount: beside a discount column it would count twice.
     */
    public function testADiscountIsNotPrintedTwice(): void
    {
        $text = $this->textOf($this->issued([$this->discounted('1000.00', PriceType::WithoutVat, '2', '10')]));

        self::assertMatchesRegularExpression('/2,00\s+h\s+1 000,00\s+10 %\s+21\s+1 800,00\s+378,00\s+2 178,00/', $text);
    }

    public function testADiscountOnAPriceWithVatPrintsThePriceWithoutVatBeforeIt(): void
    {
        $text = $this->textOf($this->issued([$this->discounted('1210.00', PriceType::WithVat, '1', '10')]));

        self::assertMatchesRegularExpression('/1 000,00\s+10 %\s+21\s+900,00\s+189,00\s+1 089,00/', $text);
    }

    public function testNoDiscountNoColumn(): void
    {
        self::assertStringNotContainsString('Sleva', $this->textOf($this->issued([$this->line()])));
    }

    #[DataProvider('languages')]
    public function testANonPayerPdfHasNoVatWord(DocumentLanguage $language): void
    {
        $rendered = $this->render($this->issuedByNonPayer(['language' => $language]));
        $text = PdfTools::text($rendered->bytes);

        self::assertStringNotContainsString('DPH', $text);
        self::assertStringNotContainsString('VAT', $text);
        self::assertStringNotContainsString('DIČ', $text);
        self::assertStringNotContainsString('daňový', $text);
        self::assertStringContainsString(DocumentLanguage::Czech === $language ? 'Datum uskutečnění' : 'Date of supply', $text);
        self::assertMatchesRegularExpression(DocumentLanguage::Czech === $language ? '/^\s*.*Faktura$/m' : '/^\s*.*Invoice$/m', $text, $text);
        self::assertSame('invoice.isdoc', PdfTools::attachment($rendered->bytes)['name']);
    }

    /**
     * @return iterable<string, array{DocumentLanguage}>
     */
    public static function languages(): iterable
    {
        yield 'Czech' => [DocumentLanguage::Czech];
        yield 'English' => [DocumentLanguage::English];
    }

    public function testReverseChargePrintsTheStatutoryWordsAndCode(): void
    {
        $document = $this->issued([$this->reverseCharge('4')]);
        $text = $this->textOf($document);

        self::assertStringContainsString('daň odvede zákazník', $text);
        self::assertMatchesRegularExpression('/Kód předmětu plnění: 4\b/', $text);
        self::assertStringContainsString('Přenesení daňové povinnosti', $text);
        self::assertSame(1, substr_count($text, 'daň odvede zákazník'));
        self::assertStringNotContainsString('0 %', $text);
        self::assertDoesNotMatchRegularExpression('/(?<![\d ])0,00/', $text);
        // Text, quantity, unit, price, base, total: no rate and no VAT figure between them.
        self::assertMatchesRegularExpression('/Work\s+1,00\s+h\s+1 000,00\s+1 000,00\s+1 000,00/', $text);
    }

    public function testEnglishReverseChargeCarriesTheCzechWordsToo(): void
    {
        $text = $this->textOf($this->issued([$this->reverseCharge('4')], ['language' => DocumentLanguage::English]));

        self::assertStringContainsString('daň odvede zákazník', $text);
        self::assertStringContainsString('The customer accounts for the tax', $text);
        self::assertMatchesRegularExpression('/Supply code: 4\b/', $text);
    }

    public function testCrossBorderReverseChargeNamesNoCode(): void
    {
        $customer = $this->customer('DE123456789', new Address('Beispielstrasse 1', 'Berlin', '10115', 'DE'));
        $this->em->flush();
        $text = $this->textOf($this->issued([$this->reverseCharge(null)], ['customerId' => $customer->id()]));

        self::assertStringContainsString('daň odvede zákazník', $text);
        self::assertStringNotContainsString('Kód předmětu plnění', $text);
        self::assertStringContainsString('Německo', $text);
    }

    public function testTheCodesOfSeveralLinesAppearInOneNote(): void
    {
        $text = $this->textOf($this->issued([$this->reverseCharge('4'), $this->reverseCharge('4'), $this->reverseCharge('12')]));

        self::assertSame(1, substr_count($text, 'daň odvede zákazník'));
        self::assertMatchesRegularExpression('/Kód předmětu plnění: 4, 12\b/', $text);
    }

    public function testAnExemptSupplySaysSoAndPrintsNoRate(): void
    {
        $text = $this->textOf($this->issued([$this->line(treatment: TaxTreatment::Exempt, kind: null)]));

        self::assertStringContainsString('Plnění je osvobozeno od daně', $text);
        self::assertSame(1, substr_count($text, 'osvobozeno od daně'));
        self::assertMatchesRegularExpression('/Work\s+1,00\s+h\s+1 000,00\s+1 000,00\s+1 000,00/', $text);
        self::assertMatchesRegularExpression('/Osvobozeno\s+1 000,00\s+1 000,00/', $text);
    }

    public function testARoundingRowIsLastInItemsAndAbsentFromTheRecap(): void
    {
        $text = $this->textOf($this->issued([$this->line('1260.00'), $this->line('0.00', text: 'Second')]));

        self::assertMatchesRegularExpression('/Zaokrouhlení\s+1,00\s+0,40\s+0\s+0,40\s+0,00\s+0,40/', $text, $text);
        self::assertSame(1, substr_count($text, 'Zaokrouhlení'));
        self::assertGreaterThan(strpos($text, 'Second'), strpos($text, 'Zaokrouhlení'));
        self::assertLessThan(strpos($text, 'Sazba DPH'), strpos($text, 'Zaokrouhlení'));
        // The recap stays unrounded and the payable amount takes the rounding.
        self::assertMatchesRegularExpression('/21 %\s+1 260,00\s+264,60\s+1 524,60/', $text);
        self::assertMatchesRegularExpression('/Celkem\s+1 260,00\s+264,60\s+1 524,60/', $text);
        self::assertStringContainsString('1 525,00 CZK', $text);
    }

    public function testADownwardRoundingIsPrintedNegative(): void
    {
        $text = $this->textOf($this->issued([$this->line('1000.37')]));

        self::assertMatchesRegularExpression('/Zaokrouhlení\s+1,00\s+-0,45/', $text);
    }

    public function testNoRoundingNoRow(): void
    {
        self::assertStringNotContainsString('Zaokrouhlení', $this->textOf($this->issued([$this->line()])));
    }

    public function testANonPayerRoundingRowHasNoVatColumns(): void
    {
        $text = $this->textOf($this->issuedByNonPayer([], '1000.50'));

        self::assertMatchesRegularExpression('/Zaokrouhlení\s+1,00\s+0,50\s+0,50/', $text);
        self::assertStringContainsString('1 001,00 CZK', $text);
    }

    public function testAStampPrintsAndAMissingStampLeavesTheCaption(): void
    {
        $name = $this->image(40);
        $this->firm->changeStamp($name);
        $this->em->flush();
        $document = $this->issued([$this->line()]);

        $with = $this->render($document)->bytes;
        self::assertStringContainsString('/Width 40', $with);
        self::assertStringContainsString($this->pdfText('Razítko a podpis: Example Firm s.r.o.'), $with);
        self::assertStringContainsString('Razítko a podpis', PdfTools::text($with));

        unlink($this->tenantImages.'/'.$name);
        $without = $this->render($document)->bytes;
        self::assertStringNotContainsString('/Width 40', $without);
        self::assertStringContainsString('Razítko a podpis', PdfTools::text($without));
    }

    public function testACopyWithAnotherStampLeavesTheOldDocumentAlone(): void
    {
        $this->firm->changeStamp($this->image(40));
        $this->em->flush();
        $old = $this->issue->issue($this->input());
        $id = $old->id();

        $firm = $this->em->find(\App\Party\Domain\LegalEntity::class, $this->firm->id());
        self::assertNotNull($firm);
        $firm->changeStamp($this->image(50));
        $this->em->flush();
        $copy = $this->issue->issue($this->input(overrides: ['variableSymbol' => '20260002']));

        self::assertStringContainsString('/Width 40', $this->render($this->of($id))->bytes);
        self::assertStringContainsString('/Width 50', $this->render($this->of($copy->id()))->bytes);
    }

    public function testAChangedLogoLeavesTheOldDocumentAlone(): void
    {
        $this->firm->changeLogo($this->image(60));
        $this->em->flush();
        $id = $this->issue->issue($this->input())->id();

        $firm = $this->em->find(\App\Party\Domain\LegalEntity::class, $this->firm->id());
        self::assertNotNull($firm);
        $firm->changeLogo($this->image(70));
        $this->em->flush();

        $pdf = $this->render($this->of($id))->bytes;
        self::assertStringContainsString('/Width 60', $pdf);
        self::assertStringNotContainsString('/Width 70', $pdf);
    }

    public function testAMissingLogoFileStillGivesAPdf(): void
    {
        $name = $this->image(60);
        $this->firm->changeLogo($name);
        $this->em->flush();
        $document = $this->issued([$this->line()]);
        unlink($this->tenantImages.'/'.$name);

        $pdf = $this->render($document)->bytes;

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringNotContainsString('/Subtype /Image', $pdf);
        self::assertStringContainsString('Example Firm s.r.o.', PdfTools::text($pdf));
    }

    public function testAnEditedContactChangesNothingInThePdf(): void
    {
        $id = $this->issue->issue($this->input())->id();
        $before = PdfTools::text($this->render($this->of($id))->bytes);

        $customer = $this->em->find(\App\Party\Domain\Party::class, $this->customer->id());
        self::assertNotNull($customer);
        $customer->rename('Renamed Customer a.s.');
        $this->em->flush();

        self::assertSame($before, PdfTools::text($this->render($this->of($id))->bytes));
    }

    public function testTheAttachmentEqualsTheIsdocOfTheDocument(): void
    {
        $document = $this->issued([$this->line('1260.00')]);
        $country = self::getContainer()->get(InvoicingCountries::class)->for('CZ');
        $expected = $country->electronicFile($document);
        self::assertNotNull($expected);

        $attachment = PdfTools::attachment($this->render($document)->bytes);

        self::assertSame('invoice.isdoc', $attachment['name']);
        self::assertSame($expected->content, $attachment['content']);
        IsdocSchema::assertValid($attachment['content']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function conforming(): iterable
    {
        yield '21 %' => ['payer'];
        yield 'non-payer' => ['nonPayer'];
        yield 'English' => ['english'];
        yield 'reverse charge and rounding' => ['reverseCharge'];
        yield 'logo and stamp' => ['images'];
    }

    #[DataProvider('conforming')]
    public function testConformsTo3a(string $scenario): void
    {
        if ('images' === $scenario) {
            $this->firm->changeLogo($this->image(60));
            $this->firm->changeStamp($this->image(40));
            $this->em->flush();
        }

        $document = match ($scenario) {
            'nonPayer' => $this->issuedByNonPayer(),
            'english' => $this->issued([$this->line()], ['language' => DocumentLanguage::English]),
            'reverseCharge' => $this->issued([$this->reverseCharge('4'), $this->line('1260.00')]),
            default => $this->issued([$this->line()]),
        };

        VeraPdf::assertConforms($this->render($document)->bytes, '3a');
    }

    public function testACashDocumentHasNoCodeAndConforms(): void
    {
        $rendered = $this->render($this->issued([$this->line()], ['paymentMethod' => PaymentMethod::Cash, 'bankAccount' => null]));

        self::assertStringNotContainsString('QR Platba+F', PdfTools::text($rendered->bytes));
        VeraPdf::assertConforms($rendered->bytes, '3a');
    }

    public function testSixtyLongLinesWithDiacriticsSpanPagesAndConform(): void
    {
        $lines = [];
        for ($index = 1; $index <= 60; ++$index) {
            $lines[] = $this->line(text: str_repeat('Příliš žluťoučký kůň úpěl ďábelské ódy ', 4).'závěr'.$index);
        }
        $rendered = $this->render($this->issued($lines));
        $text = PdfTools::text($rendered->bytes);
        $pages = PdfTools::pages($rendered->bytes);

        self::assertGreaterThan(1, $pages);
        for ($index = 1; $index <= 60; ++$index) {
            self::assertMatchesRegularExpression('/závěr'.$index.'\b/u', $text);
        }
        self::assertSame($pages, substr_count($text, 'Označení dodávky'), 'The header row repeats on each page.');
        self::assertStringContainsString('Razítko a podpis', $text);
        VeraPdf::assertConforms($rendered->bytes, '3a');
    }

    public function testMarkupInTextIsPrintedLiterally(): void
    {
        self::getContainer()->set('http_client', new MockHttpClient(static function (): never {
            self::fail('A document fetches nothing.');
        }));
        $markup = '<img src="http://example.com/x">';
        $rendered = $this->render($this->issued([$this->line(text: $markup.' <b>bold</b>')], ['description' => $markup, 'textBefore' => '<tcpdf method="x" /> & co', 'textAfter' => '<script>x</script>']));
        $text = PdfTools::text($rendered->bytes);

        self::assertStringContainsString($markup, $text);
        self::assertStringContainsString('<b>bold</b>', $text);
        self::assertStringContainsString('<tcpdf method="x" /> & co', $text);
        self::assertStringContainsString('<script>x</script>', $text);
        self::assertStringNotContainsString('/Subtype /Image', $rendered->bytes);
        VeraPdf::assertConforms($rendered->bytes, '3a');
    }

    public function testALineBreakInTextIsKept(): void
    {
        $text = $this->textOf($this->issued([$this->line(text: "First row\nSecond row")]));

        self::assertMatchesRegularExpression('/First row.*\n.*Second row/', $text);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function longValues(): iterable
    {
        yield 'twelve digits' => ['123456789012.34', '1', 'kilowatthodina'];
        yield 'small price, long quantity' => ['0.01', '999999999.9999', 'měsíc'];
        yield 'seven digits' => ['1234567.89', '1', 'měsíc'];
    }

    #[DataProvider('longValues')]
    public function testValuesNeverBreakInsideACell(string $price, string $quantity, string $unit): void
    {
        $document = $this->issued([new LineInput('Work', Quantity::fromString($quantity), $unit, Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('33.33'))]);
        $rendered = $this->render($document);
        $text = PdfTools::text($rendered->bytes);

        $line = $document->lines[0];
        $amounts = self::getContainer()->get(AmountText::class);
        foreach ([$line->net(), $line->vat(), $line->gross(), $document->gross, $line->price()] as $money) {
            self::assertStringContainsString(str_replace("\u{a0}", ' ', $amounts->grouped($money)), $text);
        }
        self::assertStringContainsString($amounts->quantity($line->quantity()->toDecimal()), $text);
        self::assertStringContainsString($unit, $text);
        self::assertStringContainsString('33,33 %', $text);
        VeraPdf::assertConforms($rendered->bytes, '3a');
    }

    public function testALargestQrAmountFitsTheBand(): void
    {
        $document = $this->issued([$this->line('8264462.80')]);

        self::assertStringContainsString('10 000 000,00 CZK', $this->textOf($this->issued([$this->line('8264462.81')])));
        self::assertMatchesRegularExpression('/\d 999 999,\d\d CZK|10 000 000,00 CZK/', $this->textOf($document));
    }

    public function testAPageFooterNamesTheNumberAndThePage(): void
    {
        $lines = [];
        for ($index = 1; $index <= 60; ++$index) {
            $lines[] = $this->line(text: 'Long line '.str_repeat('text ', 30).$index);
        }
        $document = $this->issued($lines);
        $rendered = $this->render($document);
        $pages = PdfTools::pages($rendered->bytes);

        self::assertGreaterThan(1, $pages);
        for ($page = 1; $page <= $pages; ++$page) {
            self::assertMatchesRegularExpression('/Faktura '.$document->number.' · Strana '.$page.'\/'.$pages.'/u', PdfTools::text($rendered->bytes));
        }
        VeraPdf::assertConforms($rendered->bytes, '3a');
    }

    public function testATrashedDocumentIsMarked(): void
    {
        $id = $this->issue->issue($this->input())->id();
        self::assertStringNotContainsString('V KOŠI', PdfTools::text($this->render($this->of($id))->bytes));

        $this->trash->trash($id, 'Wrong customer');
        $rendered = $this->render($this->of($id));

        self::assertStringContainsString('V KOŠI', PdfTools::text($rendered->bytes));
        VeraPdf::assertConforms($rendered->bytes, '3a');
    }

    public function testTheQuietZoneOfTheCodeIsWhite(): void
    {
        $page = imagecreatefromstring(PdfTools::firstPagePng($this->render($this->issued([$this->line()]))->bytes));
        self::assertNotFalse($page);
        [$x, $y, $side] = $this->qrBox($page);

        // Four modules of the largest code a payment gives, of 57 in 32 mm.
        $zone = 23;
        for ($offset = -$zone; $offset <= $side + $zone; ++$offset) {
            foreach ([[$x + $offset, $y - $zone], [$x + $offset, $y + $side + $zone], [$x - $zone, $y + $offset], [$x + $side + $zone, $y + $offset]] as [$px, $py]) {
                self::assertGreaterThan(235, imagecolorat($page, $px, $py) & 0xFF);
            }
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function withoutACode(): iterable
    {
        yield 'cash' => [['paymentMethod' => PaymentMethod::Cash, 'bankAccount' => null], '1000.00', 'Hotově'];
        yield 'an amount too large for a code' => [[], '90000000.00', 'CZ6508000000192000145399'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('withoutACode')]
    public function testADocumentWithoutACodeStillSaysHowToPay(array $overrides, string $price, string $expected): void
    {
        $rendered = $this->render($this->issued([$this->line($price)], $overrides));
        $text = PdfTools::text($rendered->bytes);

        self::assertStringNotContainsString('QR Platba+F', $text);
        foreach (['Celkem k úhradě', 'Datum splatnosti', 'Způsob platby', $expected] as $line) {
            self::assertStringContainsString($line, $text);
        }
        self::assertSame(1, PdfTools::pages($rendered->bytes));
        VeraPdf::assertConforms($rendered->bytes, '3a');
    }

    public function testTheCodeStaysOnItsPlateBelowATrashedMark(): void
    {
        $id = $this->issue->issue($this->input())->id();
        $this->trash->trash($id, 'Wrong customer');
        $page = imagecreatefromstring(PdfTools::firstPagePng($this->render($this->of($id))->bytes));
        self::assertNotFalse($page);

        // The mark moves the whole body down by its height, the plate and the code with it.
        $shifted = imagecrop($page, ['x' => 0, 'y' => 60, 'width' => imagesx($page), 'height' => imagesy($page) - 60]);
        self::assertNotFalse($shifted);
        self::assertGreaterThan(300, $this->qrBox($shifted)[2]);
    }

    public function testTheQrIsAtLeast30mm(): void
    {
        $image = imagecreatefromstring(PdfTools::firstPagePng($this->render($this->issued([$this->line()]))->bytes));
        self::assertNotFalse($image);

        self::assertGreaterThanOrEqual(300, $this->qrBox($image)[2], 'At least 30 mm at 10 pixels per millimetre.');
        self::assertGreaterThanOrEqual(30.0, TcpdfInvoicePdf::QR_SIZE_MM);
    }

    public function testTheQrOnThePageDecodesToThePaymentCode(): void
    {
        $document = $this->issued([$this->line('1260.00')], ['orderNumber' => 'PO-77']);
        $code = self::getContainer()->get(InvoicingCountries::class)->for('CZ')->paymentCode($document);
        self::assertStringContainsString('AM:1525.00', (string) $code);

        $page = imagecreatefromstring(PdfTools::firstPagePng($this->render($document)->bytes));
        self::assertNotFalse($page);
        // Only the code with its quiet zone, inside the plate: the decoder takes memory by the pixel.
        [$x, $y, $side] = $this->qrBox($page);
        $part = imagecrop($page, ['x' => $x - 25, 'y' => $y - 25, 'width' => $side + 50, 'height' => $side + 50]);
        self::assertNotFalse($part);
        ob_start();
        imagepng($part);
        $decoded = new QRCode()->readFromBlob((string) ob_get_clean());

        self::assertSame($code, (string) $decoded);
    }

    public function testFileNameEndsWithIsdocPdf(): void
    {
        $rendered = $this->render($this->issued([$this->line()], ['typedNumber' => 'fa/2026-7']));

        self::assertSame('FA20267-isdoc.pdf', $rendered->fileName);
        self::assertSame('FACA12-isdoc.pdf', $this->render($this->issued([$this->line()], ['typedNumber' => 'FAČá/12', 'variableSymbol' => '']))->fileName);
    }

    public function testTheDocumentIsTitledAndLabelled(): void
    {
        $document = $this->issued([$this->line()]);
        $pdf = $this->render($document)->bytes;

        self::assertStringContainsString('/Title ('.$this->pdfText('Faktura '.$document->number).')', $pdf);
        self::assertStringContainsString('/Lang ('.$this->pdfText('cs').')', $pdf);
        self::assertStringContainsString('/Alt ('.$this->pdfText('QR Platba+F faktury '.$document->number).')', $pdf);
    }

    /**
     * @param list<LineInput>      $lines
     * @param array<string, mixed> $overrides
     */
    private function issued(array $lines, array $overrides = []): IssuedDocument
    {
        return $this->of($this->issue->issue($this->input($lines, $overrides))->id());
    }

    private function of(string $id): IssuedDocument
    {
        return self::getContainer()->get(IssuedDocumentFactory::class)->of($id);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function issuedByNonPayer(array $overrides = [], string $price = '1000.00'): IssuedDocument
    {
        $firm = $this->firm(VatStatus::NotRegistered, null, registrationNumber: '12345687');
        $this->em->flush();

        return $this->issued(
            [$this->line($price, treatment: TaxTreatment::NotApplicable, kind: null)],
            [...['firmId' => $firm->id(), 'seriesId' => null], ...$overrides],
        );
    }

    private function reverseCharge(?string $code): LineInput
    {
        return $this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: $code);
    }

    private function discounted(string $price, PriceType $type, string $quantity, string $discount): LineInput
    {
        return new LineInput('Work', Quantity::fromString($quantity), 'h', Money::of($price, Currency::CZK), $type, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString($discount));
    }

    private function render(IssuedDocument $document): RenderedPdf
    {
        return $this->pdf->render($document);
    }

    private function textOf(IssuedDocument $document): string
    {
        return PdfTools::text($this->render($document)->bytes);
    }

    /** A PDF text string: UTF-16BE with a byte order mark. */
    private function pdfText(string $value): string
    {
        return "\xFE\xFF".mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
    }

    /**
     * @param positive-int $width
     */
    private function image(int $width): string
    {
        $image = imagecreatetruecolor($width, 20);
        self::assertNotFalse($image);
        ob_start();
        imagepng($image);

        return self::getContainer()->get(FirmImages::class)->store(self::getContainer()->get(TenantContext::class)->current(), (string) ob_get_clean());
    }

    /**
     * Where the code is, in pixels: the left, top and side of the box of the finder
     * squares, the only dark shapes that stand 2.5 mm tall on the white plate of the panel.
     *
     * @return array{int, int, int}
     */
    private function qrBox(\GdImage $image): array
    {
        $tops = [];
        for ($x = self::PLATE_LEFT; $x < self::PLATE_RIGHT; ++$x) {
            $top = $this->tallDarkRun($image, $x);
            if (null !== $top) {
                $tops[$x] = $top;
            }
        }
        self::assertNotEmpty($tops);
        $left = array_key_first($tops);
        $right = array_key_last($tops);

        return [$left, $tops[$left], $right - $left + 1];
    }

    private function tallDarkRun(\GdImage $image, int $x): ?int
    {
        $run = 0;
        for ($y = self::PLATE_TOP; $y < self::PLATE_BOTTOM; ++$y) {
            $run = (imagecolorat($image, $x, $y) & 0xFF) < 128 ? $run + 1 : 0;
            if (25 === $run) {
                return $y - 24;
            }
        }

        return null;
    }
}
