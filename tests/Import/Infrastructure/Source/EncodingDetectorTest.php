<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Infrastructure\Source;

use App\Import\Application\Source\Encoding;
use App\Import\Infrastructure\Source\EncodingDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EncodingDetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Encoding}>
     */
    public static function samples(): iterable
    {
        yield 'bom' => ["\xEF\xBB\xBFName;City\n", Encoding::Utf8Bom];
        yield 'plain ascii' => ["Name;City\nExample;Praha\n", Encoding::Utf8];
        yield 'valid utf-8' => ["Ústí nad Labem\n", Encoding::Utf8];
        yield 'windows-1250 with s caron' => ["P\xF8\xEDli\x9A\n", Encoding::Windows1250];
        yield 'windows-1250 with z caron' => ["\x9E\n", Encoding::Windows1250];
        yield 'windows-1250 with S caron' => ["\x8A\n", Encoding::Windows1250];
        yield 'windows-1250 with Z caron' => ["\x8E\n", Encoding::Windows1250];
        yield 'windows-1250 with t caron' => ["\xF8\n", Encoding::Windows1250];
        yield 'windows-1250 with e caron' => ["\xEC\n", Encoding::Windows1250];
        yield 'windows-1250 with u ring' => ["\xF9\n", Encoding::Windows1250];
        yield 'windows-1250 with c caron' => ["\xE8\n", Encoding::Windows1250];
        yield 'other single byte text' => ["Caf\xE9\n", Encoding::Windows1252];
    }

    #[DataProvider('samples')]
    public function testDetect(string $bytes, Encoding $expected): void
    {
        self::assertSame($expected, new EncodingDetector()->detect($bytes, false));
    }

    public function testCutOffMultibyteCharacterAtTheEndOfATruncatedSampleStaysUtf8(): void
    {
        $sample = 'Ústí'.substr('ž', 0, 1);

        self::assertSame(Encoding::Utf8, new EncodingDetector()->detect($sample, true));
    }

    public function testCutOffCharacterInAWholeFileIsNotUtf8(): void
    {
        $sample = 'Ústí'.substr('ž', 0, 1);

        self::assertNotSame(Encoding::Utf8, new EncodingDetector()->detect($sample, false));
    }
}
