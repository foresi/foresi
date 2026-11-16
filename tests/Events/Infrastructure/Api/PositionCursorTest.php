<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Infrastructure\Api;

use App\Events\Infrastructure\Api\PositionCursor;
use App\Shared\Domain\Id;
use App\Shared\Infrastructure\Api\Cursor;
use App\Shared\Infrastructure\Api\InvalidParameter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cursor of the event feed is the position in base64url, and nothing else is
 * taken for one.
 */
final class PositionCursorTest extends TestCase
{
    public function testAPositionIsBase64UrlAndReadsBack(): void
    {
        self::assertSame('cDo0Mg', PositionCursor::encode(42));
        self::assertSame(42, PositionCursor::decode('cDo0Mg'));
        foreach ([1, 9, 1234567, 999999999999999999] as $position) {
            self::assertSame($position, PositionCursor::decode(PositionCursor::encode($position)));
        }
    }

    public function testNoCursorStartsAtTheBeginning(): void
    {
        self::assertNull(PositionCursor::decode(null));
        self::assertNull(PositionCursor::decode(''));
        self::assertNull(PositionCursor::next(null));
        self::assertSame('cDo3', PositionCursor::next(7));
    }

    #[DataProvider('garbage')]
    public function testAnythingElseIsRefused(string $cursor): void
    {
        try {
            PositionCursor::decode($cursor);
            self::fail('The cursor was taken.');
        } catch (InvalidParameter $refused) {
            self::assertSame('cursor', $refused->parameter);
            self::assertSame('The cursor is not one this API gave.', $refused->getMessage());
        }
    }

    public function testACursorOfAnIdListIsRefused(): void
    {
        $this->expectException(InvalidParameter::class);

        PositionCursor::decode(Cursor::encode(Id::generate()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function garbage(): iterable
    {
        yield 'text' => ['garbage'];
        yield 'not base64' => ['!!!'];
        yield 'a number' => [rtrim(strtr(base64_encode('42'), '+/', '-_'), '=')];
        yield 'zero' => [rtrim(strtr(base64_encode('p:0'), '+/', '-_'), '=')];
        yield 'negative' => [rtrim(strtr(base64_encode('p:-5'), '+/', '-_'), '=')];
        yield 'padded' => ['cDo0Mg=='];
        yield 'trailing text' => [rtrim(strtr(base64_encode('p:42x'), '+/', '-_'), '=')];
        yield 'leading zero' => [rtrim(strtr(base64_encode('p:042'), '+/', '-_'), '=')];
        yield 'a uuid' => [Id::generate()];
    }
}
