<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Application;

use App\Party\Application\RegistryOutcome;
use App\Party\Domain\RegistryCheck;
use App\Party\Domain\RegistryResult;
use App\Party\Domain\RegistrySource;
use PHPUnit\Framework\TestCase;

final class RegistryOutcomeTest extends TestCase
{
    public function testOldestIsTheEarlierOfTheTwoChecks(): void
    {
        $early = new \DateTimeImmutable('2026-10-30 08:00:00');
        $late = new \DateTimeImmutable('2026-10-30 10:00:00');

        $outcome = new RegistryOutcome(
            new RegistryCheck(RegistrySource::Vies, RegistryResult::Valid, $late),
            new RegistryCheck(RegistrySource::UnreliablePayer, RegistryResult::Reliable, $early),
            [],
        );

        self::assertSame($early, $outcome->oldest());
    }

    public function testOldestIsTheViesCheckWhenThereIsNoReliabilityCheck(): void
    {
        $at = new \DateTimeImmutable('2026-10-30 08:00:00');

        $outcome = new RegistryOutcome(new RegistryCheck(RegistrySource::Vies, RegistryResult::Valid, $at), null, []);

        self::assertSame($at, $outcome->oldest());
    }

    public function testAnswersFromBeforeTheRequestCameFromTheCache(): void
    {
        $answered = new \DateTimeImmutable('2026-10-30 08:00:00');
        $outcome = new RegistryOutcome(new RegistryCheck(RegistrySource::Vies, RegistryResult::Valid, $answered), null, []);

        self::assertTrue($outcome->answeredBefore(new \DateTimeImmutable('2026-10-30 08:00:01')));
        self::assertFalse($outcome->answeredBefore($answered));
        self::assertFalse($outcome->answeredBefore(new \DateTimeImmutable('2026-10-30 07:59:59')));
    }
}
