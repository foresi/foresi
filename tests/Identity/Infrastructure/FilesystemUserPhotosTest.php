<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Infrastructure;

use Foresi\Identity\Application\ProfileRefused;
use Foresi\Identity\Application\UserPhotos;
use Foresi\Identity\Infrastructure\Photo\FilesystemUserPhotos;
use PHPUnit\Framework\TestCase;

final class FilesystemUserPhotosTest extends TestCase
{
    private const string USER = '0193b0a2-7c1e-7000-8000-000000000001';

    private string $root;
    private FilesystemUserPhotos $photos;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/foresi-user-photos-'.bin2hex(random_bytes(4));
        $this->photos = new FilesystemUserPhotos($this->root);
    }

    protected function tearDown(): void
    {
        foreach ($this->files() as $file) {
            unlink($file);
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function testANonSquareImageIsCroppedToTheMiddleSquareOfTheStoredSide(): void
    {
        // Red left, green middle, blue right: the middle square is all green.
        $image = imagecreatetruecolor(800, 400);
        imagefilledrectangle($image, 0, 0, 199, 399, (int) imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, 200, 0, 599, 399, (int) imagecolorallocate($image, 0, 255, 0));
        imagefilledrectangle($image, 600, 0, 799, 399, (int) imagecolorallocate($image, 0, 0, 255));

        $this->photos->store(self::USER, $this->png($image));

        $stored = $this->stored();
        self::assertSame(UserPhotos::SIDE, imagesx($stored));
        self::assertSame(UserPhotos::SIDE, imagesy($stored));
        [$red, $green] = $this->rgb($stored, 5, 256);
        self::assertLessThan(40, $red);
        self::assertGreaterThan(200, $green);
        self::assertSame(0o600, fileperms((string) $this->photos->path(self::USER)) & 0o777);
    }

    public function testTransparencyIsLaidOnWhite(): void
    {
        $image = imagecreatetruecolor(100, 100);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));

        $this->photos->store(self::USER, $this->png($image));

        self::assertSame([255, 255, 255], $this->rgb($this->stored(), 256, 256));
    }

    public function testTheStoredPhotoCarriesNoMetadataOfTheUpload(): void
    {
        $image = imagecreatetruecolor(64, 64);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        // An APP1 (EXIF) segment right after the start of image, as a camera writes it.
        $exif = "Exif\0\0".'GPS 50.08 14.42';
        $withExif = "\xFF\xD8\xFF\xE1".pack('n', \strlen($exif) + 2).$exif.substr($jpeg, 2);

        $this->photos->store(self::USER, $withExif);

        $stored = (string) file_get_contents((string) $this->photos->path(self::USER));
        self::assertStringNotContainsString('Exif', $stored);
        self::assertStringNotContainsString('GPS', $stored);
    }

    public function testANewPhotoReplacesTheOldOneAndRemovingDeletesIt(): void
    {
        self::assertNull($this->photos->path(self::USER));
        $this->photos->store(self::USER, $this->png(imagecreatetruecolor(10, 10)));
        $white = imagecreatetruecolor(10, 10);
        imagefill($white, 0, 0, (int) imagecolorallocate($white, 255, 255, 255));

        $this->photos->store(self::USER, $this->png($white));

        self::assertCount(1, $this->files());
        self::assertSame([255, 255, 255], $this->rgb($this->stored(), 10, 10));

        $this->photos->remove(self::USER);
        self::assertNull($this->photos->path(self::USER));
        $this->photos->remove(self::USER);
    }

    public function testWebpIsTaken(): void
    {
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagewebp($image);

        $this->photos->store(self::USER, (string) ob_get_clean());

        self::assertNotNull($this->photos->path(self::USER));
    }

    public function testAnUploadOverFiveMegabytesIsRefusedUnread(): void
    {
        $this->assertRefused(ProfileRefused::PHOTO_TOO_LARGE, str_repeat('a', UserPhotos::MAX_BYTES + 1));
    }

    public function testWhatIsNoImageIsRefused(): void
    {
        $this->assertRefused(ProfileRefused::PHOTO_TYPE, '');
        $this->assertRefused(ProfileRefused::PHOTO_TYPE, '%PDF-1.7 renamed to photo.jpg');
        $gif = imagecreatetruecolor(10, 10);
        ob_start();
        imagegif($gif);
        $this->assertRefused(ProfileRefused::PHOTO_TYPE, (string) ob_get_clean());
    }

    public function testTooManyPixelsAreRefusedBeforeTheImageIsDecoded(): void
    {
        // A PNG header claiming 20000 x 20000 pixels, with nothing behind it.
        $ihdr = 'IHDR'.pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));

        $this->assertRefused(ProfileRefused::PHOTO_DIMENSIONS, $png);
    }

    public function testOnlyAUserIdNamesAPhoto(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->photos->path('../../etc/passwd');
    }

    private function assertRefused(string $reason, string $bytes): void
    {
        try {
            $this->photos->store(self::USER, $bytes);
            self::fail('The upload was stored.');
        } catch (ProfileRefused $refused) {
            self::assertSame($reason, $refused->reason());
            self::assertSame('photo', $refused->field());
        }
        self::assertNull($this->photos->path(self::USER));
    }

    private function stored(): \GdImage
    {
        $image = imagecreatefromjpeg((string) $this->photos->path(self::USER));
        self::assertInstanceOf(\GdImage::class, $image);

        return $image;
    }

    /**
     * @return array{int, int, int}
     */
    private function rgb(\GdImage $image, int $x, int $y): array
    {
        $color = imagecolorat($image, $x, $y);

        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
    }

    private function png(\GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->root.'/*');

        return false === $files ? [] : $files;
    }
}
