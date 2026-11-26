<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Application;

use Foresi\Activities\Application\ActivitiesAccess;
use Foresi\Activities\Application\ActivitiesStore;
use Foresi\Activities\Application\ActivityLinksForOtherModules;
use Foresi\Activities\Application\ActivityTargets;
use Foresi\Activities\Application\Api\TrashActivityInput;
use Foresi\Activities\Application\RecordsForOtherModules;
use Foresi\Activities\Contract\ActivityLinks;
use Foresi\Activities\Contract\RecordedActivity;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Activities\Support\ActivitiesTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Whether an activity is linked to a record, as another module asks it: only
 * an activity not in the trash, to the very kind and id, that the person sees.
 */
final class ActivityLinksTest extends ActivitiesTestCase
{
    public function testAnActivityIsLinkedToTheRecordsItNames(): void
    {
        $dealId = Id::generate();
        $this->deals->add($dealId, 'Example deal');
        $id = $this->record([['kind' => 'contact', 'id' => $this->contactId]]);

        self::assertTrue($this->links()->isLinked($id, 'contact', $this->contactId));
        self::assertFalse($this->links()->isLinked($id, 'deal', $this->contactId), 'Another kind.');
        self::assertFalse($this->links()->isLinked($id, 'contact', Id::generate()), 'Another record.');
        self::assertFalse($this->links()->isLinked($id, 'contact', 'not-an-id'));
        self::assertFalse($this->links()->isLinked(Id::generate(), 'contact', $this->contactId), 'No such activity.');
        self::assertFalse($this->links()->isLinked('not-an-id', 'contact', $this->contactId));
    }

    public function testAnActivityInTheTrashIsNotLinked(): void
    {
        $id = $this->record([['kind' => 'contact', 'id' => $this->contactId]]);
        $this->activities()->trash(new TrashActivityInput($id));

        self::assertFalse($this->links()->isLinked($id, 'contact', $this->contactId));
    }

    public function testAnActivityThePersonDoesNotSeeIsNotLinked(): void
    {
        $id = $this->record([['kind' => 'contact', 'id' => $this->contactId]]);
        $this->contacts->hide($this->contactId);

        self::assertTrue($this->links()->isLinked($id, 'contact', $this->contactId), 'The owner sees their own activity.');

        $this->signIn($this->person('member@example.com', \Foresi\Identity\Domain\Role::Member));
        self::assertFalse($this->links()->isLinked($id, 'contact', $this->contactId));
        self::getContainer()->get(TokenStorageInterface::class)->setToken(null);
        self::assertFalse($this->links()->isLinked($id, 'contact', $this->contactId), 'Nobody is signed in.');
    }

    /**
     * @param list<array{kind: string, id: string}> $links
     */
    private function record(array $links): string
    {
        $this->type('email');

        return new RecordsForOtherModules($this->activities(), $this->store())->record(new RecordedActivity('email', 'Re: Offer', '', new \DateTimeImmutable('2026-11-20 09:30:00', new \DateTimeZone('UTC')), $this->owner->user()->id(), $links));
    }

    private function links(): ActivityLinks
    {
        $container = self::getContainer();

        return new ActivityLinksForOtherModules($container->get(ActivitiesStore::class), $container->get(ActivityTargets::class), $container->get(ActivitiesAccess::class));
    }
}
