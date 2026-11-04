<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\InboundToken;
use PHPUnit\Framework\TestCase;

/**
 * A token is 128 random bits in 26 characters of base32. The bits are random,
 * so the checks run over many tokens: what holds for every token is checked on
 * each, and what holds for the bits as a whole is checked on their spread.
 */
final class InboundTokenTest extends TestCase
{
    private const int TOKENS = 400;

    public function testEveryTokenIs26CharactersOfTheAlphabet(): void
    {
        for ($i = 0; $i < self::TOKENS; ++$i) {
            self::assertMatchesRegularExpression('/^[a-z2-7]{26}$/D', InboundToken::generate());
        }
    }

    public function testTheLastCharacterHoldsThreeBitsAndTwoZeros(): void
    {
        $last = [];
        for ($i = 0; $i < self::TOKENS; ++$i) {
            $last[] = substr(InboundToken::generate(), -1);
        }

        // Three bits padded with two zeros: only every fourth character of the alphabet.
        self::assertSame([], array_values(array_diff($last, ['a', 'e', 'i', 'm', 'q', 'u', 'y', '4'])));
        // Each byte gives all eight of its bits, so the last three bits are as random as the rest, not padding:
        // about one token in eight ends in "a".
        self::assertLessThan(self::TOKENS / 4, \count(array_keys($last, 'a', true)));
    }

    public function testTheHashIsOfTheLowercaseToken(): void
    {
        $token = InboundToken::generate();

        self::assertSame(hash('sha256', $token), InboundToken::hash($token));
        self::assertSame(InboundToken::hash($token), InboundToken::hash(strtoupper($token)));
    }
}
