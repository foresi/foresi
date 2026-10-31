<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Application;

use App\Import\Application\ImportFileName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportFileNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'plain' => ['kontakty.csv', 'kontakty.csv'];
        yield 'diacritics' => ['Příliš žluťoučký kůň.xlsx', 'Příliš žluťoučký kůň.xlsx'];
        yield 'windows path' => ['C:\\fakepath\\contacts.csv', 'contacts.csv'];
        yield 'unix path' => ['../../etc/contacts.csv', 'contacts.csv'];
        yield 'control characters' => ["con\x00tacts\r\n\u{202E}.csv", 'contacts.csv'];
        yield 'leading dots' => ['..hidden.csv', 'hidden.csv'];
        yield 'nothing left' => ["../\x01", 'import'];
        yield 'invalid utf-8' => ["kontakty\xFF.csv", 'kontakty?.csv'];
    }

    #[DataProvider('names')]
    public function testCleansTheName(string $sent, string $kept): void
    {
        self::assertSame($kept, ImportFileName::normalise($sent));
    }

    public function testCutsALongNameToTheLimitAndKeepsTheExtension(): void
    {
        $name = ImportFileName::normalise(str_repeat('č', 300).'.xlsx');

        self::assertSame(ImportFileName::MAX_LENGTH, mb_strlen($name));
        self::assertStringEndsWith('č.xlsx', $name);
    }

    public function testALongTailAfterTheLastDotIsNotKeptAsAnExtension(): void
    {
        $name = ImportFileName::normalise('a.'.str_repeat('b', 300));

        self::assertSame(ImportFileName::MAX_LENGTH, mb_strlen($name));
        self::assertStringStartsWith('a.bbb', $name);
    }
}
