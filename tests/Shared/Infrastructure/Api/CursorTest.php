<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Api;

use App\Shared\Domain\Id;
use App\Shared\Infrastructure\Api\Cursor;
use App\Shared\Infrastructure\Api\InvalidParameter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CursorTest extends TestCase
{
    private const string ID = '01963c5e-0000-7000-8000-000000000001';

    public function testACursorIsTheIdInBase64UrlAndReadsBack(): void
    {
        $cursor = Cursor::encode(self::ID);

        self::assertSame('MDE5NjNjNWUtMDAwMC03MDAwLTgwMDAtMDAwMDAwMDAwMDAx', $cursor);
        self::assertSame(self::ID, Cursor::decode($cursor));
        $id = Id::generate();
        self::assertSame($id, Cursor::decode(Cursor::encode($id)));
    }

    public function testTheAlphabetIsUrlSafeWithoutPadding(): void
    {
        self::assertSame('Pz8-Pw', Cursor::encode('??>?'));
        self::assertSame('Pz8_', Cursor::encode('???'));
    }

    public function testTheNextCursorIsNoneWhenNothingFollows(): void
    {
        self::assertNull(Cursor::next(null));
        self::assertSame(Cursor::encode(self::ID), Cursor::next(self::ID));
    }

    public function testNoCursorAndAnEmptyOneAskForTheFirstSlice(): void
    {
        self::assertNull(Cursor::decode(null));
        self::assertNull(Cursor::decode(''));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignCursors(): iterable
    {
        yield 'not base64' => ['***'];
        yield 'no id' => [Cursor::encode('not-an-id')];
        yield 'an id in capitals' => [Cursor::encode(strtoupper(self::ID))];
        yield 'an id of another version' => [Cursor::encode('01963c5e-0000-4000-8000-000000000001')];
        yield 'a space before it' => [' '.Cursor::encode(self::ID)];
        yield 'the plain alphabet' => [base64_encode(self::ID).'='];
    }

    #[DataProvider('foreignCursors')]
    public function testACursorThisApiDidNotGiveIsRefused(string $cursor): void
    {
        try {
            Cursor::decode($cursor);
            self::fail('The cursor was read.');
        } catch (InvalidParameter $refused) {
            self::assertSame('cursor', $refused->parameter);
            self::assertSame('The cursor is not one this API gave.', $refused->getMessage());
            self::assertSame(400, $refused->getStatusCode());
        }
    }
}
