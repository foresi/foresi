<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Unit\CzechRepublic;

use Foresi\Country\Integration\Purchasing\CzechRepublic\PaymentQrReader;
use Foresi\Country\Integration\Purchasing\CzechRepublic\SpaydParser;
use Foresi\Purchasing\Contract\Reading\DocumentFile;
use Foresi\Purchasing\Contract\Reading\ReaderFailed;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Infrastructure\Reading\SmalotPdfFiles;
use Foresi\Tests\Support\ReceivedFileFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentQrReaderTest extends TestCase
{
    private const string SPAYD = 'SPD*1.0*ACC:CZ6508000000192000145399*AM:1210.50*CC:CZK*X-VS:2026100*DT:20261115';

    private PaymentQrReader $reader;

    protected function setUp(): void
    {
        $this->reader = new PaymentQrReader(new SmalotPdfFiles(), new SpaydParser());
    }

    public function testDescribesItself(): void
    {
        self::assertSame('payment_qr', $this->reader->source());
        self::assertTrue($this->reader->automatic());
        self::assertTrue($this->reader->supports(new DocumentFile('a.png', 'image/png', '')));
        self::assertTrue($this->reader->supports(new DocumentFile('a.pdf', 'application/pdf', '')));
        self::assertFalse($this->reader->supports(new DocumentFile('a.xml', 'application/xml', '')));
    }

    public function testReadsQrFromPng(): void
    {
        $reading = $this->reader->read(new DocumentFile('invoice.png', 'image/png', ReceivedFileFixtures::qrPng(self::SPAYD)));

        self::assertNotNull($reading);
        $total = $reading->get(ReadField::Total);
        self::assertSame(121050, $total?->value);
        self::assertSame('CZ6508000000192000145399', $reading->get(ReadField::BankAccount)?->value);
        self::assertSame('payment_qr', $total->source);
    }

    public function testReadsQrFromPdfPage(): void
    {
        $pdf = ReceivedFileFixtures::pdf(1, [1 => ReceivedFileFixtures::qrPng(self::SPAYD)]);

        $reading = $this->reader->read(new DocumentFile('invoice.pdf', 'application/pdf', $pdf));

        self::assertSame('2026-11-15', $reading?->get(ReadField::DueDate)?->value);
    }

    public function testTheFirstCodeTheParserAcceptsWins(): void
    {
        $web = ReceivedFileFixtures::qrPng('https://example.com/invoice');
        $first = ReceivedFileFixtures::qrPng('SPD*1.0*ACC:CZ6508000000192000145399*AM:1.00');
        $second = ReceivedFileFixtures::qrPng('SPD*1.0*ACC:CZ6508000000192000145399*AM:2.00');
        $pdf = ReceivedFileFixtures::pdf(2, [1 => $web, 2 => $first]);

        $reading = $this->reader->read(new DocumentFile('invoice.pdf', 'application/pdf', $pdf));
        self::assertSame(100, $reading?->get(ReadField::Total)?->value);

        $pdf = ReceivedFileFixtures::pdf(2, [1 => $second, 2 => $first]);
        $reading = $this->reader->read(new DocumentFile('invoice.pdf', 'application/pdf', $pdf));
        self::assertSame(200, $reading?->get(ReadField::Total)?->value);
    }

    public function testAnImageWithoutAQrCodeFindsNothing(): void
    {
        $blank = imagecreatetruecolor(200, 200);
        self::assertNotFalse($blank);
        ob_start();
        imagepng($blank);
        $png = (string) ob_get_clean();

        self::assertNull($this->reader->read(new DocumentFile('blank.png', 'image/png', $png)));
    }

    public function testACodeThatIsNotAPaymentFindsNothing(): void
    {
        $png = ReceivedFileFixtures::qrPng('https://example.com/invoice');

        self::assertNull($this->reader->read(new DocumentFile('invoice.png', 'image/png', $png)));
    }

    public function testPdfWith500PagesReadsOnlyTwo(): void
    {
        $qr = ReceivedFileFixtures::qrPng(self::SPAYD);

        $third = ReceivedFileFixtures::pdf(500, [3 => $qr]);
        $last = ReceivedFileFixtures::pdf(500, [500 => $qr]);
        $second = ReceivedFileFixtures::pdf(500, [2 => $qr]);

        $start = microtime(true);
        self::assertNull($this->reader->read(new DocumentFile('a.pdf', 'application/pdf', $third)));
        self::assertNull($this->reader->read(new DocumentFile('b.pdf', 'application/pdf', $last)));
        self::assertNotNull($this->reader->read(new DocumentFile('c.pdf', 'application/pdf', $second)));
        self::assertLessThan(10.0, microtime(true) - $start);
    }

    public function testEncryptedPdfFailsWithReaderFailed(): void
    {
        $pdf = ReceivedFileFixtures::pdf(1, [], ReceivedFileFixtures::encryption());

        $this->expectException(ReaderFailed::class);
        $this->reader->read(new DocumentFile('invoice.pdf', 'application/pdf', $pdf));
    }

    public function testACorruptPdfFailsWithReaderFailed(): void
    {
        $this->expectException(ReaderFailed::class);
        $this->reader->read(new DocumentFile('invoice.pdf', 'application/pdf', '%PDF-1.7 broken'));
    }

    public function testAnImageOver25MbFailsWithoutBeingDecoded(): void
    {
        $this->expectException(ReaderFailed::class);
        $this->reader->read(new DocumentFile('big.png', 'image/png', str_repeat("\0", 25 * 1024 * 1024)));
    }

    public function testTheSizeLimitOfAnImageIsInclusive(): void
    {
        $limit = 20 * 1024 * 1024;

        $this->assertRefused('The file is not an image.', str_repeat("\0", $limit));
        $this->assertRefused('The image is over the size limit.', str_repeat("\0", $limit + 1));
    }

    public function testTheLimitOfPixelsIsInclusiveAndIsTheProductOfTheSides(): void
    {
        // The header alone claims the size; at the limit the image goes on to be decoded, which cannot be done.
        self::assertNull($this->reader->read(new DocumentFile('a.png', 'image/png', $this->pngHeaderOfSize(5000, 5000))));

        $this->assertRefused('The image has too many pixels.', $this->pngHeaderOfSize(5001, 5000));
        $this->assertRefused('The image has too many pixels.', $this->pngHeaderOfSize(25_000_001, 1));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function largeCanvases(): iterable
    {
        yield 'wide' => [4200, 2100];
        yield 'tall' => [2100, 4200];
        yield 'square' => [2600, 2600];
        yield 'a little over the side the decoder gets' => [2001, 1500];
    }

    #[DataProvider('largeCanvases')]
    public function testACodeOnACanvasLargerThanTheDecoderGetsIsStillRead(int $width, int $height): void
    {
        $qr = imagecreatefromstring(ReceivedFileFixtures::qrPng(self::SPAYD, 4));
        self::assertNotFalse($qr);
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));
        self::assertNotFalse($canvas);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        $side = min($width, $height) - 200;
        imagecopyresampled($canvas, $qr, 100, 100, 0, 0, $side, $side, imagesx($qr), imagesy($qr));
        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();
        unset($qr, $canvas);

        $reading = $this->reader->read(new DocumentFile('large.png', 'image/png', $png));

        self::assertSame(121050, $reading?->get(ReadField::Total)?->value);
    }

    public function testAnImageWithTooManyPixelsFailsWithoutBeingDecoded(): void
    {
        $this->expectException(ReaderFailed::class);
        $this->reader->read(new DocumentFile('huge.png', 'image/png', $this->pngHeaderOfSize(30000, 30000)));
    }

    public function testAnImageThatIsNotAnImageFailsWithReaderFailed(): void
    {
        $this->expectException(ReaderFailed::class);
        $this->reader->read(new DocumentFile('broken.png', 'image/png', 'not an image'));
    }

    public function testALargeImageIsReadWithinALowMemoryBudget(): void
    {
        // A QR code scaled up onto a 6000 x 4000 canvas.
        $qr = imagecreatefromstring(ReceivedFileFixtures::qrPng(self::SPAYD, 4));
        self::assertNotFalse($qr);
        $canvas = imagecreatetruecolor(6000, 4000);
        self::assertNotFalse($canvas);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $qr, 200, 200, 0, 0, 3000, 3000, imagesx($qr), imagesy($qr));
        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();
        unset($qr, $canvas);

        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        $reading = $this->reader->read(new DocumentFile('large.png', 'image/png', $png));
        $used = memory_get_peak_usage() - $before;

        self::assertSame(121050, $reading?->get(ReadField::Total)?->value);
        self::assertLessThan(200 * 1024 * 1024, $used);
    }

    public function testABlankLargeImageStaysWithinTheMemoryBudget(): void
    {
        $canvas = imagecreatetruecolor(5000, 4000);
        self::assertNotFalse($canvas);
        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();
        unset($canvas);

        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        self::assertNull($this->reader->read(new DocumentFile('blank.png', 'image/png', $png)));

        self::assertLessThan(200 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    private function assertRefused(string $message, string $bytes): void
    {
        try {
            $this->reader->read(new DocumentFile('a.png', 'image/png', $bytes));
        } catch (ReaderFailed $e) {
            self::assertSame($message, $e->getMessage());

            return;
        }
        self::fail('Expected a refusal: '.$message);
    }

    /**
     * Only the header of a PNG that claims the size; nothing could decode it.
     */
    private function pngHeaderOfSize(int $width, int $height): string
    {
        $header = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$header.pack('N', crc32('IHDR'.$header));
    }
}
