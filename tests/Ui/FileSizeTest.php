<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Ui\FileSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileSizeTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function sizes(): iterable
    {
        yield 'bytes' => [512, 'cs', "512\u{a0}B"];
        yield 'a kilobyte' => [1024, 'en', "1\u{a0}kB"];
        yield 'megabytes in Czech' => [1_258_291, 'cs', "1,2\u{a0}MB"];
        yield 'megabytes in English' => [1_258_291, 'en', "1.2\u{a0}MB"];
        yield 'the upload limit' => [50 * 1024 * 1024, 'cs', "50\u{a0}MB"];
        yield 'nothing' => [0, 'en', "0\u{a0}B"];
        yield 'a byte' => [1, 'en', "1\u{a0}B"];
        yield 'a negative size' => [-5, 'en', "0\u{a0}B"];
        yield 'the last byte below a kilobyte' => [1023, 'en', "1023\u{a0}B"];
        yield 'one decimal only' => [1_289_000, 'en', "1.2\u{a0}MB"];
        yield 'terabytes' => [1024 ** 4, 'en', "1\u{a0}TB"];
        yield 'more than terabytes stay terabytes' => [1024 ** 5, 'en', "1,024\u{a0}TB"];
        yield 'gigabytes' => [3 * 1024 ** 3, 'en', "3\u{a0}GB"];
    }

    #[DataProvider('sizes')]
    public function testASizeIsShownInTheLargestWholeUnitWithOneDecimal(int $bytes, string $locale, string $expected): void
    {
        self::assertSame($expected, FileSize::format($bytes, $locale));
    }
}
