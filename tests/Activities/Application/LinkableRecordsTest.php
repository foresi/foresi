<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Application;

use Foresi\Activities\Application\ActivitiesAccess;
use Foresi\Activities\Application\ActivitiesStore;
use Foresi\Activities\Application\ActivityTargets;
use Foresi\Activities\Application\Api\TrashActivityInput;
use Foresi\Activities\Application\LinkableRecordsForOtherModules;
use Foresi\Activities\Application\RecordsForOtherModules;
use Foresi\Activities\Contract\LinkableRecords;
use Foresi\Activities\Contract\LinkedRecord;
use Foresi\Activities\Contract\RecordedActivity;
use Foresi\Identity\Domain\Role;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Activities\Support\ActivitiesTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * What another module asks before it links a record: the kinds offered, whether
 * a record may be linked, how it shows, and who sees an activity through it.
 */
final class LinkableRecordsTest extends ActivitiesTestCase
{
    public function testTheKindsAndTheRecordsThePersonMayLink(): void
    {
        $dealId = Id::generate();
        $this->deals->add($dealId, 'Example deal');
        $hidden = Id::generate();
        $this->deals->add($hidden, 'Hidden deal');
        $this->deals->hide($hidden);

        self::assertSame(['contact', 'deal'], $this->records()->kinds());
        self::assertTrue($this->records()->canLink('deal', $dealId));
        self::assertFalse($this->records()->canLink('deal', $hidden));
        self::assertFalse($this->records()->canLink('deal', 'not-an-id'));
        self::assertFalse($this->records()->canLink('spaceship', $dealId));
        self::assertEquals(new LinkedRecord('deal', $dealId, 'Example deal', '/deals/'.$dealId), $this->records()->describe('deal', $dealId));
        self::assertNull($this->records()->describe('deal', $hidden));
        self::assertNull($this->records()->describe('spaceship', $dealId));
    }

    public function testAnActivityIsSeenThroughItsRecordsAndNotFromTheTrash(): void
    {
        $id = $this->record([['kind' => 'contact', 'id' => $this->contactId]]);
        $lonely = $this->record([]);
        $this->signIn($member = $this->person('member@example.com', Role::Member));
        $this->em->flush();

        self::assertTrue($this->records()->seesActivity($id), 'Through the contact.');
        self::assertFalse($this->records()->seesActivity($lonely), 'Without records, the owner\'s only.');
        self::assertFalse($this->records()->seesActivity(Id::generate()));

        $this->signIn($this->owner);
        $this->activities()->trash(new TrashActivityInput($id));
        $this->signIn($member);
        self::assertFalse($this->records()->seesActivity($id));
    }

    public function testIsLinkedAsksNoOneAndCountsTheTrashToo(): void
    {
        $id = $this->record([['kind' => 'contact', 'id' => $this->contactId]]);
        $lonely = $this->record([]);
        $this->activities()->trash(new TrashActivityInput($id));
        self::getContainer()->get(TokenStorageInterface::class)->setToken(null);

        self::assertTrue($this->records()->isLinked($id));
        self::assertFalse($this->records()->isLinked($lonely));
        self::assertFalse($this->records()->isLinked(Id::generate()));
    }

    /**
     * @param list<array{kind: string, id: string}> $links
     */
    private function record(array $links): string
    {
        $this->type('email');

        return new RecordsForOtherModules($this->activities(), $this->store())->record(new RecordedActivity('email', 'Re: Offer', 'Body of the mail.', new \DateTimeImmutable('2026-11-20 09:30:00', new \DateTimeZone('UTC')), $this->owner->user()->id(), $links));
    }

    private function records(): LinkableRecords
    {
        $container = self::getContainer();

        return new LinkableRecordsForOtherModules($container->get(ActivityTargets::class), $container->get(ActivitiesStore::class), $container->get(ActivitiesAccess::class));
    }
}
