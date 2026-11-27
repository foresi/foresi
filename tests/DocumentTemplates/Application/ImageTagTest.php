<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\DocumentTemplates\Application;

use Foresi\DocumentTemplates\Application\ImageTag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The <img> of an image variable: fitted into 52 by 16 mm, a local file for the
 * PDF and inline bytes for the browser preview.
 */
final class ImageTagTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->files);
    }

    #[Test]
    public function aWideImageFillsTheWidthAndATallOneTheHeight(): void
    {
        $wide = $this->image(520, 80, 'png');
        $tall = $this->image(80, 160, 'jpeg');

        self::assertSame('<img src="'.$wide.'" alt="Logo &amp; &quot;firm&quot;" width="52.00mm" height="8.00mm">', (string) ImageTag::forPdf($wide, 'Logo & "firm"'));
        self::assertSame('<img src="'.$tall.'" alt="Logo" width="8.00mm" height="16.00mm">', (string) ImageTag::forPdf($tall, 'Logo'));
    }

    #[Test]
    public function thePreviewCarriesTheBytesWithASizeInPixels(): void
    {
        $wide = $this->image(520, 80, 'png');
        $tall = $this->image(80, 160, 'jpeg');

        self::assertSame('<img src="data:image/png;base64,'.base64_encode((string) file_get_contents($wide)).'" alt="Logo" width="197" height="30">', (string) ImageTag::forPreview($wide, 'Logo'));
        self::assertSame('<img src="data:image/jpeg;base64,'.base64_encode((string) file_get_contents($tall)).'" alt="Logo" width="30" height="60">', (string) ImageTag::forPreview($tall, 'Logo'));
    }

    #[Test]
    public function aMissingFileOrOneThatIsNoPngOrJpegGivesNoTag(): void
    {
        $text = (string) tempnam(sys_get_temp_dir(), 'foresi-image');
        $this->files[] = $text;
        file_put_contents($text, 'not an image');
        $gif = $this->image(10, 10, 'gif');

        foreach ([$text, $gif, sys_get_temp_dir().'/foresi-no-such-image.png'] as $file) {
            self::assertNull(ImageTag::forPdf($file, 'Logo'), $file);
            self::assertNull(ImageTag::forPreview($file, 'Logo'), $file);
        }
    }

    #[Test]
    public function thePlaceholderTakesTheBoxAndShowsTheEscapedLabel(): void
    {
        self::assertSame(
            '<span style="display:inline-flex;align-items:center;justify-content:center;width:52mm;height:16mm;border:1px dashed #9ca3af;color:#6b7280;font-size:9pt;vertical-align:middle">Logo &lt;b&gt;</span>',
            (string) ImageTag::placeholder('Logo <b>'),
        );
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function image(int $width, int $height, string $format): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'foresi-image');
        $this->files[] = $file;
        $image = imagecreatetruecolor($width, $height);
        \assert(false !== $image);
        match ($format) {
            'png' => imagepng($image, $file),
            'jpeg' => imagejpeg($image, $file),
            default => imagegif($image, $file),
        };

        return $file;
    }
}
