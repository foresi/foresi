<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Ui\Avatar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AvatarTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'two words' => ['Jana Example', 'JE'];
        yield 'more than two' => ['Jana Marie Example', 'JM'];
        yield 'one word' => ['Šimon', 'Š'];
        yield 'accents are capitalised' => ['élan ždár', 'ÉŽ'];
        yield 'space around' => ["  Jana \t Example\n", 'JE'];
        yield 'no name' => ['  ', ''];
    }

    #[DataProvider('names')]
    public function testInitialsAreTheFirstLettersOfTheFirstTwoWords(string $name, string $initials): void
    {
        self::assertSame($initials, Avatar::initials($name));
    }
}
