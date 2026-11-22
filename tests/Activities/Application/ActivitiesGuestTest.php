<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Application;

use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\Api\ArchiveActivityTypeInput;
use Foresi\Activities\Application\Api\CompleteActivityInput;
use Foresi\Activities\Application\Api\CreateActivityInput;
use Foresi\Activities\Application\Api\CreateActivityTypeInput;
use Foresi\Activities\Application\Api\RestoreActivityInput;
use Foresi\Activities\Application\Api\TrashActivityInput;
use Foresi\Activities\Application\Api\UpdateActivityInput;
use Foresi\Activities\Application\Api\UpdateActivityTypeInput;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Tests\Activities\Support\ActivitiesTestCase;

/**
 * A guest signed in is refused by every use case before anything else: the
 * activity and the type they name exist, so only the access check refuses them.
 */
final class ActivitiesGuestTest extends ActivitiesTestCase
{
    public function testAGuestIsRefusedByEveryUseCase(): void
    {
        $activity = $this->planned('Kick-off meeting');
        $type = $this->type('meeting');
        $this->signIn($this->guest());

        $this->assertForbidden(fn (): \Foresi\Activities\Domain\Activity => $this->activities()->create(new CreateActivityInput($type->id(), 'Call', plannedAt: '2026-11-25T10:00:00+01:00')));
        $this->assertForbidden(fn (): \Foresi\Activities\Domain\Activity => $this->activities()->update(new UpdateActivityInput($activity->id(), subject: 'Renamed')));
        $this->assertForbidden(fn (): array => $this->activities()->complete(new CompleteActivityInput($activity->id())));
        $this->assertForbidden(fn (): \Foresi\Activities\Domain\Activity => $this->activities()->trash(new TrashActivityInput($activity->id())));
        $this->assertForbidden(fn (): \Foresi\Activities\Domain\Activity => $this->activities()->restore(new RestoreActivityInput($activity->id())));
        $this->assertForbidden(fn (): \Foresi\Activities\Domain\ActivityType => $this->types()->create(new CreateActivityTypeInput('Site visit', 'site_visit', 'timed', 30)));
        $this->assertForbidden(fn (): \Foresi\Activities\Domain\ActivityType => $this->types()->update(new UpdateActivityTypeInput($type->id(), name: 'Renamed')));
        $this->assertForbidden(fn (): \Foresi\Activities\Domain\ActivityType => $this->types()->archive(new ArchiveActivityTypeInput($type->id())));
        $this->assertForbidden(fn (): array => $this->reads()->types());
        $this->assertForbidden(fn (): \Foresi\Activities\Domain\Activity => $this->reads()->activity($activity->id()));
        $this->assertForbidden(fn (): array => $this->reads()->timeline('contact', $this->contactId));
        $this->assertForbidden(fn (): array => $this->reads()->myPlanned(null, null));
    }

    private function guest(): Membership
    {
        // A guest always represents a contact.
        $party = new Party(PartyKind::Person, 'Alex Example', null, null, '');
        $user = new User('guest@example.com', 'hash', 'Example Guest');
        $guest = new Membership($user, Role::Guest, $party);
        $this->em->persist($party);
        $this->em->persist($user);
        $this->em->persist($guest);
        $this->em->flush();

        return $guest;
    }

    private function assertForbidden(callable $use): void
    {
        try {
            $use();
            self::fail('Forbidden.');
        } catch (ActivitiesRefused $refused) {
            self::assertSame('forbidden', $refused->reason());
        }
    }
}
