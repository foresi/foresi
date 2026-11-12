<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Domain\Event;

use App\Shared\Domain\Event\EventSubject;
use App\Shared\Domain\Id;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventSubjectTest extends TestCase
{
    public function testAcceptsATypeAndAnId(): void
    {
        $id = Id::generate();
        $subject = new EventSubject('invoice', $id);

        self::assertSame('invoice', $subject->type);
        self::assertSame($id, $subject->id);
    }

    public function testAcceptsUnderscoresAndDigitsInTheType(): void
    {
        self::assertSame('bank_account2', (new EventSubject('bank_account2', 'x'))->type);
    }

    public function testAcceptsAnIdOfExactlySixtyFourCharacters(): void
    {
        self::assertSame(64, strlen((new EventSubject('invoice', str_repeat('a', 64)))->id));
    }

    #[DataProvider('badTypes')]
    public function testRejectsAMalformedType(string $type): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EventSubject($type, Id::generate());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badTypes(): iterable
    {
        yield 'upper case' => ['Invoice'];
        yield 'empty' => [''];
        yield 'leading digit' => ['1invoice'];
        yield 'leading underscore' => ['_invoice'];
        yield 'hyphen' => ['bank-account'];
        yield 'trailing newline' => ["invoice\n"];
    }

    public function testRejectsAnEmptyId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EventSubject('invoice', '');
    }

    public function testRejectsAnIdOfSixtyFiveCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EventSubject('invoice', str_repeat('a', 65));
    }
}
