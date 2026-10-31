<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Infrastructure\Source;

use App\Import\Application\Source\Encoding;
use App\Import\Infrastructure\Source\TextDecoder;
use PHPUnit\Framework\TestCase;

final class TextDecoderTest extends TestCase
{
    public function testWindows1250(): void
    {
        $bytes = "P\xF8\xEDli\x9A \x9E lu\x9D ou\xE8k\xFD k\xF9\xF2 \x80";

        self::assertSame('Příliš ž luť oučký kůň €', new TextDecoder()->decode($bytes, Encoding::Windows1250));
    }

    public function testWindows1250UndefinedByteBecomesReplacementCharacter(): void
    {
        self::assertSame("a\u{FFFD}b", new TextDecoder()->decode("a\x81b", Encoding::Windows1250));
    }

    public function testIso88592(): void
    {
        self::assertSame('Příliš', new TextDecoder()->decode("P\xF8\xEDli\xB9", Encoding::Iso88592));
    }

    public function testWindows1252(): void
    {
        self::assertSame('Café €', new TextDecoder()->decode("Caf\xE9 \x80", Encoding::Windows1252));
    }

    public function testUtf8StaysAndInvalidBytesAreScrubbed(): void
    {
        $decoder = new TextDecoder();

        self::assertSame('Ústí', $decoder->decode('Ústí', Encoding::Utf8));
        self::assertSame('Ústí', $decoder->decode('Ústí', Encoding::Utf8Bom));
        self::assertSame('a?b', $decoder->decode("a\xFFb", Encoding::Utf8));
    }
}
