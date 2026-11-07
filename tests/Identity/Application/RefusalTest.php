<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Application;

use App\Identity\Application\InvitationRefused;
use App\Identity\Application\PeopleRefused;
use PHPUnit\Framework\TestCase;

final class RefusalTest extends TestCase
{
    public function testAnInvitationRefusalSaysWhy(): void
    {
        $refusal = new InvitationRefused('not_pending');

        self::assertSame('not_pending', $refusal->reason);
        self::assertSame('The invitation is refused: not_pending.', $refusal->getMessage());
    }

    public function testAChangeRefusalSaysWhy(): void
    {
        $refusal = new PeopleRefused('last_owner');

        self::assertSame('last_owner', $refusal->reason);
        self::assertSame('The change is refused: last_owner.', $refusal->getMessage());
    }

    public function testOnlyKnownReasonsAreGiven(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PeopleRefused('whatever');
    }
}
