<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Calendar\Domain;

use Foresi\Calendar\Domain\CalendarFeed;
use Foresi\Calendar\Domain\FeedToken;
use PHPUnit\Framework\TestCase;

/**
 * The secret of a feed address: 256 random bits, of which the feed keeps only the SHA-256.
 */
final class FeedTokenTest extends TestCase
{
    public function testTheTokenHas256BitsInUnpaddedBase64url(): void
    {
        $token = FeedToken::generate();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        $bytes = base64_decode(strtr($token, '-_', '+/'), true);
        self::assertIsString($bytes);
        self::assertSame(32, \strlen($bytes));
        self::assertNotSame($token, FeedToken::generate());
    }

    public function testOnlyItsHashIsStored(): void
    {
        $token = FeedToken::generate();
        $feed = new CalendarFeed('0190a000-0000-7000-8000-000000000001', 'Phone', FeedToken::hash($token), new \DateTimeImmutable('2026-11-22 10:00 UTC'));

        self::assertSame(hash('sha256', $token), $feed->tokenHash());
        foreach ((array) $feed as $value) {
            self::assertNotSame($token, $value);
        }
    }

    public function testAMalformedTokenIsNotAToken(): void
    {
        self::assertTrue(FeedToken::wellFormed(FeedToken::generate()));
        self::assertFalse(FeedToken::wellFormed('short'));
        self::assertFalse(FeedToken::wellFormed(str_repeat('a', 42).'='));
    }

    public function testAFeedRefusesAnEmptyOrLongName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CalendarFeed('0190a000-0000-7000-8000-000000000001', '  ', FeedToken::hash(FeedToken::generate()), new \DateTimeImmutable('2026-11-22 10:00 UTC'));
    }

    public function testRevokingKeepsTheFirstTime(): void
    {
        $feed = new CalendarFeed('0190a000-0000-7000-8000-000000000001', 'Phone', FeedToken::hash(FeedToken::generate()), new \DateTimeImmutable('2026-11-22 10:00 UTC'));

        $feed->revoke(new \DateTimeImmutable('2026-11-22 11:00 UTC'));
        $feed->revoke(new \DateTimeImmutable('2026-11-22 12:00 UTC'));

        self::assertEquals(new \DateTimeImmutable('2026-11-22 11:00 UTC'), $feed->revokedAt());
        self::assertTrue($feed->isRevoked());
    }

    public function testAFetchIsRecordedAtMostOnceAnHour(): void
    {
        $feed = new CalendarFeed('0190a000-0000-7000-8000-000000000001', 'Phone', FeedToken::hash(FeedToken::generate()), new \DateTimeImmutable('2026-11-22 10:00 UTC'));

        self::assertTrue($feed->fetched(new \DateTimeImmutable('2026-11-22 10:05 UTC')));
        self::assertFalse($feed->fetched(new \DateTimeImmutable('2026-11-22 11:04:59 UTC')));
        self::assertEquals(new \DateTimeImmutable('2026-11-22 10:05 UTC'), $feed->lastFetchedAt());
        self::assertTrue($feed->fetched(new \DateTimeImmutable('2026-11-22 11:05 UTC')));
        self::assertEquals(new \DateTimeImmutable('2026-11-22 11:05 UTC'), $feed->lastFetchedAt());
    }
}
