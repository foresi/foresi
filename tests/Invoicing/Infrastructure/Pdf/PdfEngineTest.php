<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Pdf;

use App\Invoicing\Infrastructure\Pdf\EmbeddedFile;
use App\Invoicing\Infrastructure\Pdf\PdfEngine;
use App\Invoicing\Infrastructure\Pdf\QrFigure;
use App\Tests\Support\VeraPdf;
use Com\Tecnick\Pdf\PdfConformance;
use Com\Tecnick\Pdf\Tcpdf;
use PHPUnit\Framework\TestCase;

/**
 * The gate for the invoice PDF: the engine must produce a tagged PDF/A-3a
 * document that carries the ISDOC file, and must read nothing it was not given.
 */
final class PdfEngineTest extends TestCase
{
    private const string XML = '<?xml version="1.0" encoding="UTF-8"?><Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.2"><ID>20260001</ID></Invoice>';
    private const string CZECH = 'ěščřžýáíéůú ĚŠČŘŽÝÁÍÉŮÚ';
    private const string PAYLOAD = 'SPD*1.0*ACC:CZ6508000000192000145399*AM:1210.00*CC:CZK*MSG:20260001';

    private string $workDirectory;
    private string $logoDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir().'/foresi-pdf-test-'.bin2hex(random_bytes(6));
        $this->logoDirectory = $this->workDirectory.'/logos';
        mkdir($this->logoDirectory, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->workDirectory, $this->logoDirectory] as $directory) {
            $files = glob($directory.'/*.png');
            foreach (false === $files ? [] : $files as $file) {
                unlink($file);
            }
        }
        @rmdir($this->logoDirectory);
        @rmdir($this->workDirectory);
    }

    public function testAnInvoiceLikePageConformsToPdfA3a(): void
    {
        $this->writePng($this->logoDirectory.'/logo.png', alpha: false);
        $pdf = $this->engine()->document('Invoice 20260001', 'cs', $this->tableHtml(), new EmbeddedFile('invoice.isdoc', self::XML, 'text/xml'));

        self::assertStringStartsWith('%PDF-', $pdf);
        VeraPdf::assertConforms($pdf, '3a');
    }

    /**
     * The negative control of the check every other test leans on: a document valid
     * for the lower level of PDF/A, without the tags of level a, must fail.
     */
    public function testTheConformanceCheckTellsTheLevelsApart(): void
    {
        $directory = realpath(__DIR__.'/../../../../resources/pdf-fonts');
        self::assertIsString($directory);
        $library = \dirname((string) new \ReflectionClass(Tcpdf::class)->getFileName()).'/include';
        $pdf = new Tcpdf(unit: 'mm', isunicode: true, subsetfont: true, compress: true, mode: PdfConformance::Pdfa3b, fileOptions: ['allowedHosts' => [], 'allowedPaths' => [$directory, $library]]);
        $font = $pdf->font->insert($pdf->pon, 'intertnum', '', 9.0, ifile: $directory.'/intertnum.json');
        $pdf->addPage();
        $pdf->page->addContent($font['out'].$pdf->getTextCell('Untagged', 15, 15, 100));
        $untagged = $pdf->getOutPDFString();

        VeraPdf::assertConforms($untagged, '3b');
        VeraPdf::assertRefuses($untagged, '3a');
    }

    public function testTheEmbeddedFileIsListedAsTheStandardRequires(): void
    {
        $raw = $this->engine()->document('T', 'cs', '<p>x</p>', new EmbeddedFile('invoice.isdoc', self::XML, 'text/xml'));

        self::assertStringContainsString('/AFRelationship /Alternative', $raw);
        self::assertStringContainsString('/EmbeddedFiles', $raw);
        self::assertStringContainsString('/Subtype /text#2Fxml', $raw);
        self::assertStringContainsString('/AF [', $raw);
        // F and UF both name the file; UF is the UTF-16 form of the same text.
        self::assertStringContainsString('/F (invoice.isdoc)', $raw);
        self::assertStringContainsString('/UF ('.$this->text('invoice.isdoc').')', $raw);
    }

    public function testADocumentWithoutAFileCarriesNone(): void
    {
        $raw = $this->engine()->document('T', 'cs', '<p>x</p>', null);

        self::assertStringNotContainsString('/EmbeddedFiles', $raw);
        self::assertStringNotContainsString('/AFRelationship', $raw);
        VeraPdf::assertConforms($raw, '3a');
    }

    public function testTheDocumentDeclaresItsTitleAndLanguage(): void
    {
        $raw = $this->engine()->document('Invoice 20260001', 'cs', '<p>x</p>', null);

        self::assertStringContainsString('/Lang ('.$this->text('cs').')', $raw);
        self::assertStringContainsString('/DisplayDocTitle true', $raw);
        self::assertStringContainsString('/MarkInfo', $raw);
        self::assertStringContainsString('/Creator ('.$this->text('Foresi').')', $raw);
        self::assertStringContainsString('/Title ('.$this->text('Invoice 20260001').')', $raw);
    }

    public function testCzechGlyphsAreEmbedded(): void
    {
        $raw = $this->engine()->document('T', 'cs', '<p>'.self::CZECH.'</p>', null);

        // Every character is mapped back to text, or copying the invoice would break.
        self::assertStringContainsString('/ToUnicode', $raw);
        self::assertStringContainsString('/FontFile2', $raw);
        VeraPdf::assertConforms($raw, '3a');
    }

    public function testPngWithAlphaAndAQrFigureStillConform(): void
    {
        $this->writePng($this->logoDirectory.'/logo.png', alpha: true);
        $qr = new QrFigure(self::PAYLOAD, 'QR payment code', 150.0, 230.0, 30.0);
        $pdf = $this->engine()->document('Invoice 20260001', 'cs', $this->tableHtml(), new EmbeddedFile('invoice.isdoc', self::XML, 'text/xml'), $qr);

        self::assertStringContainsString('/Figure', $pdf);
        self::assertStringContainsString('/Alt ('.$this->text('QR payment code').')', $pdf);
        self::assertStringContainsString('/SMask', $pdf);
        VeraPdf::assertConforms($pdf, '3a');
    }

    public function testNothingIsReadOutsideTheAllowedDirectories(): void
    {
        $this->writePng($this->logoDirectory.'/inside.png', alpha: false);
        $this->writePng($this->workDirectory.'/outside.png', alpha: false);
        $html = '<p>x</p><img src="%s" alt="a"><img src="%s" alt="b"><img src="http://example.com/remote.png" alt="c">';

        $control = $this->engine()->document('T', 'cs', sprintf($html, $this->logoDirectory.'/inside.png', $this->logoDirectory.'/inside.png'), null);
        $outside = $this->engine()->document('T', 'cs', sprintf($html, $this->workDirectory.'/outside.png', '/etc/hosts'), null);

        // The control proves the images load from the allowed directory at all.
        self::assertStringContainsString('/Subtype /Image', $control);
        self::assertStringNotContainsString('/Subtype /Image', $outside);
        self::assertStringNotContainsString('localhost', $outside);
    }

    /** A PDF text string: UTF-16BE with a byte order mark. */
    private function text(string $value): string
    {
        return "\xFE\xFF".mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
    }

    private function engine(): PdfEngine
    {
        return new PdfEngine(logoDirectories: [$this->logoDirectory]);
    }

    private function tableHtml(): string
    {
        return '<img src="'.$this->logoDirectory.'/logo.png" alt="Company logo" width="60" height="20">'
            .'<h1>Invoice 20260001</h1>'
            .'<table cellpadding="2"><tr><td>Issue date</td><td>2026-11-01</td></tr><tr><td>Customer</td><td>Example Customer s.r.o. '.self::CZECH.'</td></tr></table>'
            .'<table border="1" cellpadding="3"><thead><tr><th>Item</th><th>Quantity</th><th>Amount</th></tr></thead>'
            .'<tbody><tr><td>Consulting</td><td>10</td><td>1 000,00</td></tr><tr><td>Hosting</td><td>1</td><td>210,00</td></tr></tbody></table>';
    }

    private function writePng(string $path, bool $alpha): void
    {
        $image = imagecreatetruecolor(60, 20);
        \assert(false !== $image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $fill = imagecolorallocatealpha($image, 30, 90, 200, $alpha ? 64 : 0);
        \assert(false !== $fill);
        imagefilledrectangle($image, 0, 0, 59, 19, $fill);
        imagepng($image, $path);
    }
}
