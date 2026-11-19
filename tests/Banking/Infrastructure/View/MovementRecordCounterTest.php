<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Infrastructure\View;

use Foresi\Audit\Application\AuditActor;
use Foresi\Audit\Domain\ActorKind;
use Foresi\Banking\Infrastructure\View\MovementRecordCounter;
use Foresi\Identity\Application\MembershipDirectory;
use Foresi\Identity\Application\Organisation;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Identity\Domain\Role;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Tests\Banking\Support\ScreenStores;
use PHPUnit\Framework\TestCase;

final class MovementRecordCounterTest extends TestCase
{
    public function testItCountsTheMovementsOrTheMailOfAView(): void
    {
        $stores = new ScreenStores([], 4, 9);
        $counter = new MovementRecordCounter($stores->movements, $stores->messages, $this->staff(true));

        self::assertSame([['banking.movements'], 'payments:read'], [$counter->lists(), $counter->scope()]);
        self::assertSame(4, $counter->count('banking.movements', ViewFilter::all(new Condition('direction', 'is', 'incoming'))));
        self::assertSame(9, $counter->count('banking.movements', ViewFilter::all(new Condition('bank_mail', 'is', true))));
        self::assertSame(['movements:[{"field":"direction","op":"is","value":"incoming"}]:', 'messages:[{"field":"bank_mail","op":"is","value":true}]'], $stores->asked);
    }

    public function testItCountsNoOtherList(): void
    {
        $stores = new ScreenStores([], 4, 9);

        $this->expectException(\InvalidArgumentException::class);
        new MovementRecordCounter($stores->movements, $stores->messages, $this->staff(true))->count('party.contacts', new ViewFilter());
    }

    public function testOnlyStaffCountTheBank(): void
    {
        $stores = new ScreenStores([], 4, 9);

        $this->expectException(\DomainException::class);
        new MovementRecordCounter($stores->movements, $stores->messages, $this->staff(false))->count('banking.movements', new ViewFilter());
    }

    private function staff(bool $staff): StaffCheck
    {
        $memberships = new readonly class($staff) implements MembershipDirectory {
            public function __construct(private bool $staff)
            {
            }

            public function roleOf(string $userId): Role
            {
                return $this->staff ? Role::Member : Role::Guest;
            }

            public function organisationOf(string $userId): Organisation
            {
                return new Organisation('Example Firm', $this->roleOf($userId));
            }
        };
        $actor = new class implements AuditActor {
            public function userId(): string
            {
                return '01963c5e-0000-7000-8000-0000000000aa';
            }

            public function kind(): ActorKind
            {
                return ActorKind::User;
            }
        };

        return new StaffCheck($memberships, $actor);
    }
}
