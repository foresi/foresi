<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Infrastructure\Source;

use App\Import\Infrastructure\Source\DelimiterDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DelimiterDetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function samples(): iterable
    {
        yield 'comma' => ["a,b,c\n1,2,3\n4,5,6\n", ','];
        yield 'semicolon' => ["a;b;c\n1;2;3\n", ';'];
        yield 'tab' => ["a\tb\tc\n1\t2\t3\n", "\t"];
        yield 'pipe' => ["a|b|c\n1|2|3\n", '|'];
        yield 'semicolon with decimal commas' => ["name;amount\nx;1,5\ny;2,5\nz;3,5\n", ';'];
        yield 'comma inside quotes is ignored' => ["a;b\n\"x, y, z\";1\n\"p, q, r\";2\n", ';'];
        yield 'newline inside quotes is one record' => ["a,b\n\"x\ny\",1\n\"p\nq\",2\n", ','];
        yield 'title rows without delimiters' => ["Title\nGenerated\na;b;c\n1;2;3\n4;5;6\n", ';'];
        yield 'carriage returns' => ["a;b\r\n1;2\r\n3;4\r\n", ';'];
        yield 'single column falls back to comma' => ["a\nb\nc\n", ','];
        yield 'empty sample falls back to comma' => ['', ','];
        yield 'most consistent wins over most frequent' => ["a;b;c\n1;2;3\n4;5;6\n7,8,9,10,11,12\n", ';'];
        yield 'higher count wins a tie' => ["a;b,c,d\n1;2,3,4\n", ','];
    }

    #[DataProvider('samples')]
    public function testDetect(string $sample, string $expected): void
    {
        self::assertSame($expected, new DelimiterDetector()->detect($sample));
    }

    public function testOnlyTheFirstTwentyRecordsDecide(): void
    {
        $sample = str_repeat("a;b\n", 20).str_repeat("a,b,c,d\n", 30);

        self::assertSame(';', new DelimiterDetector()->detect($sample));
    }
}
