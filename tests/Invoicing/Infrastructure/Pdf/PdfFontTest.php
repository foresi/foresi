<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure\Pdf;

use Foresi\Invoicing\Infrastructure\Pdf\PdfFont;
use PHPUnit\Framework\TestCase;

/**
 * The font measures with the faces Shared bundles, and a missing face names the
 * file it looked for instead of failing as broken JSON.
 */
final class PdfFontTest extends TestCase
{
    public function testReadsTheFacesSharedBundles(): void
    {
        $font = new PdfFont();

        self::assertSame([], $font->unprintable('Příliš žluťoučký kůň 123,45 Kč'));
        self::assertSame(['☃'], $font->unprintable('snow ☃'));
        self::assertGreaterThan(0.0, $font->width('Invoice', 9.0));
    }

    public function testAMissingFaceNamesTheFile(): void
    {
        $font = new PdfFont(sys_get_temp_dir().'/no-pdf-fonts-here');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('~no-pdf-fonts-here/intertnum\.json cannot be read~');

        $font->unprintable('text');
    }
}
