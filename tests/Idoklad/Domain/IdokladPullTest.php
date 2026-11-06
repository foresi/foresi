<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Idoklad\Domain;

use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\IdokladResource;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use PHPUnit\Framework\TestCase;

final class IdokladPullTest extends TestCase
{
    public function testAPullSucceedsOnlyWhenItFinishedWithoutAnError(): void
    {
        $pull = $this->pull();
        self::assertFalse($pull->succeeded());

        $pull->finish(new \DateTimeImmutable('2026-11-06 10:05:00'), 12);
        self::assertTrue($pull->succeeded());
        self::assertSame(12, $pull->requestsUsed());

        $pull->finish(new \DateTimeImmutable('2026-11-06 10:06:00'), 13, str_repeat('é', 300));
        self::assertFalse($pull->succeeded());
        self::assertSame(str_repeat('é', 255), $pull->error());
    }

    public function testTheLatestChangeIsTheGreatestMomentSeen(): void
    {
        $pull = $this->pull('2026-10-01T00:00:00');
        self::assertNull($pull->latestChange());

        foreach (['2026-10-02T10:00:00.5', null, '2026-10-02T10:00:00', '2026-10-03T08:00:00', '2026-10-01T23:59:59.999'] as $change) {
            $pull->noteChange($change);
        }
        $pull->counted(IdokladResource::Contacts, 4);
        $pull->counted(IdokladResource::Contacts, 5);

        self::assertSame('2026-10-03T08:00:00', $pull->latestChange());
        self::assertSame(['Contacts' => 5], $pull->counts());
        self::assertSame('2026-10-01T00:00:00', $pull->changedSince());
    }

    public function testAMomentWithAZoneIsNotOneIdokladWrites(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pull('2026-10-01T00:00:00Z');
    }

    private function pull(?string $changedSince = null): IdokladPull
    {
        $firm = new LegalEntity('Example s.r.o.', new RegistrationNumber('CZ', '12345678'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');

        return new IdokladPull($firm, new \DateTimeImmutable('2026-11-06 10:00:00'), $changedSince);
    }
}
