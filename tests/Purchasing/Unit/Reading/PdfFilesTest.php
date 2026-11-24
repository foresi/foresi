<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Unit\Reading;

use Foresi\Purchasing\Contract\Reading\DocumentFile;
use Foresi\Purchasing\Contract\Reading\PdfFiles;
use Foresi\Purchasing\Contract\Reading\ReaderFailed;
use Foresi\Purchasing\Infrastructure\Reading\SmalotPdfFiles;
use Foresi\Tests\Support\ReceivedFileFixtures;
use PHPUnit\Framework\TestCase;

final class PdfFilesTest extends TestCase
{
    private PdfFiles $pdfFiles;

    protected function setUp(): void
    {
        $this->pdfFiles = new SmalotPdfFiles();
    }

    public function testListsEmbeddedXml(): void
    {
        $pdf = $this->pdf(ReceivedFileFixtures::pdf(embeddedName: 'invoice.xml', embeddedContent: '<Invoice>example</Invoice>'));

        $files = $this->pdfFiles->embedded($pdf);

        self::assertCount(1, $files);
        self::assertSame('invoice.xml', $files[0]->fileName);
        self::assertSame('<Invoice>example</Invoice>', $files[0]->bytes);
        self::assertTrue($files[0]->isXml());
    }

    public function testAPdfWithoutEmbeddedFilesListsNone(): void
    {
        self::assertSame([], $this->pdfFiles->embedded($this->pdf(ReceivedFileFixtures::pdf(2))));
    }

    public function testExtractsAnEmbeddedImageAsPng(): void
    {
        $png = ReceivedFileFixtures::qrPng('https://example.com/pay');
        [$width, $height] = $this->dimensions($png);

        $images = $this->pdfFiles->pageImages($this->pdf(ReceivedFileFixtures::pdf(1, [1 => $png])), 2);

        self::assertCount(1, $images);
        self::assertStringStartsWith("\x89PNG", $images[0]);
        self::assertSame([$width, $height], $this->dimensions($images[0]));
    }

    public function testExtractsAJpegAsPng(): void
    {
        $image = imagecreatetruecolor(40, 30);
        self::assertNotFalse($image);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        $images = $this->pdfFiles->pageImages($this->pdf(ReceivedFileFixtures::pdf(1, [1 => $jpeg])), 1);

        self::assertCount(1, $images);
        self::assertSame([40, 30], $this->dimensions($images[0]));
    }

    public function testOnlyTheFirstPagesAreSearched(): void
    {
        $png = ReceivedFileFixtures::qrPng('https://example.com/pay');
        $pdf = $this->pdf(ReceivedFileFixtures::pdf(3, [3 => $png]));

        self::assertSame([], $this->pdfFiles->pageImages($pdf, 2));
        self::assertCount(1, $this->pdfFiles->pageImages($pdf, 3));
    }

    public function testAPdfOf500PagesIsReadQuicklyAndOnlyForItsFirstPages(): void
    {
        $png = ReceivedFileFixtures::qrPng('https://example.com/pay');
        $pdf = $this->pdf(ReceivedFileFixtures::pdf(500, [2 => $png, 500 => $png]));

        $start = microtime(true);
        $images = $this->pdfFiles->pageImages($pdf, 2);
        $elapsed = microtime(true) - $start;

        self::assertCount(1, $images);
        self::assertLessThan(5.0, $elapsed);
    }

    public function testAnEncryptedPdfFails(): void
    {
        $pdf = $this->pdf(ReceivedFileFixtures::pdf(1, [], ReceivedFileFixtures::encryption()));

        $this->expectException(ReaderFailed::class);
        $this->pdfFiles->pageImages($pdf, 2);
    }

    public function testAnEncryptedPdfFailsOnItsEmbeddedFilesToo(): void
    {
        $pdf = $this->pdf(ReceivedFileFixtures::pdf(1, [], ReceivedFileFixtures::encryption()));

        $this->expectException(ReaderFailed::class);
        $this->pdfFiles->embedded($pdf);
    }

    public function testACorruptPdfFails(): void
    {
        $this->expectException(ReaderFailed::class);
        $this->pdfFiles->embedded($this->pdf('%PDF-1.7 this is not a document'));
    }

    public function testAFileOverTheSizeLimitFails(): void
    {
        $this->expectException(ReaderFailed::class);
        $this->pdfFiles->pageImages($this->pdf('%PDF-1.7'.str_repeat(' ', 26 * 1024 * 1024)), 2);
    }

    private function pdf(string $bytes): DocumentFile
    {
        return new DocumentFile('invoice.pdf', 'application/pdf', $bytes);
    }

    /**
     * @return array{int, int}
     */
    private function dimensions(string $image): array
    {
        $size = getimagesizefromstring($image);
        self::assertIsArray($size);

        return [$size[0], $size[1]];
    }
}
