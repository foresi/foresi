<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Application;

use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\Api\CreateActivityInput;
use Foresi\Activities\Application\Api\TrashActivityInput;
use Foresi\Activities\Domain\Activity;
use Foresi\Attachment\Application\AttachmentPermissions;
use Foresi\Identity\Domain\Role;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Activities\Support\ActivitiesTestCase;

/**
 * Who sees an activity: whoever sees one of its records; when none is visible
 * (none given, or all gone) only its owner, in the timeline and in the reads
 * alike (the calendar feed: Integration\Calendar). Its files follow it.
 */
final class ActivityVisibilityTest extends ActivitiesTestCase
{
    public function testVisibleThroughAnyLinkedTarget(): void
    {
        $dealId = Id::generate();
        $this->deals->add($dealId, 'Audit');
        $member = $this->person('member@example.com', Role::Member);
        $this->em->flush();
        $activity = $this->planned('Kick-off meeting', links: [['kind' => 'contact', 'id' => $this->contactId], ['kind' => 'deal', 'id' => $dealId]]);

        $this->signIn($member);
        $this->contacts->hide($this->contactId);
        self::assertSame($activity->id(), $this->reads()->activity($activity->id())->id(), 'The deal is still visible.');
        self::assertSame([$activity->id()], $this->ids($this->reads()->timeline('deal', $dealId)));
        self::assertSame([$activity->id()], $this->ids($this->reads()->timeline('contact', $this->contactId)), 'A record the member cannot see still shows what they see through another.');

        $this->deals->hide($dealId);
        $this->assertNotFound(fn (): Activity => $this->reads()->activity($activity->id()));
        self::assertSame([], $this->reads()->timeline('deal', $dealId));
        $this->assertNotFound(fn (): Activity => $this->activities()->trash(new TrashActivityInput($activity->id())));
    }

    public function testOnlyOwnerSeesActivityWhoseTargetsAreGone(): void
    {
        $member = $this->person('member@example.com', Role::Member);
        $this->em->flush();
        $planned = $this->planned('Kick-off meeting');
        $unlinked = $this->activities()->create(new CreateActivityInput($this->type('task')->id(), 'Think it over', plannedOn: '2026-11-24'));

        // The client is archived: the activity stays, and its link with it.
        $this->contacts->remove($this->contactId);

        self::assertSame($planned->id(), $this->reads()->activity($planned->id())->id(), 'Its owner still sees it.');
        self::assertSame([$planned->id()], $this->ids($this->reads()->timeline('contact', $this->contactId)));
        self::assertCount(1, $this->store()->links($planned));

        $this->signIn($member);
        $this->assertNotFound(fn (): Activity => $this->reads()->activity($planned->id()));
        $this->assertNotFound(fn (): Activity => $this->reads()->activity($unlinked->id()));
        self::assertSame([], $this->reads()->timeline('contact', $this->contactId));
    }

    public function testAttachmentsFollowActivity(): void
    {
        $member = $this->person('member@example.com', Role::Member);
        $this->em->flush();
        $activity = $this->planned('Kick-off meeting');
        $permissions = self::getContainer()->get(AttachmentPermissions::class);

        self::assertTrue($permissions->canSee(Activity::ATTACHMENT_KIND, $activity->id()));
        self::assertTrue($permissions->canAttach(Activity::ATTACHMENT_KIND, $activity->id()));
        self::assertFalse($permissions->canSee(Activity::ATTACHMENT_KIND, Id::generate()));

        $this->contacts->remove($this->contactId);
        $this->signIn($member);
        self::assertFalse($permissions->canSee(Activity::ATTACHMENT_KIND, $activity->id()), 'Whoever does not see the activity does not see its files.');
        self::assertFalse($permissions->canAttach(Activity::ATTACHMENT_KIND, $activity->id()));

        $this->signIn($this->owner);
        $this->activities()->trash(new TrashActivityInput($activity->id()));
        self::assertFalse($permissions->canSee(Activity::ATTACHMENT_KIND, $activity->id()), 'An activity in the trash shows no files.');
    }

    /**
     * @param list<Activity> $activities
     *
     * @return list<string>
     */
    private function ids(array $activities): array
    {
        return array_map(static fn (Activity $activity): string => $activity->id(), $activities);
    }

    private function assertNotFound(callable $read): void
    {
        try {
            $read();
            self::fail('Not found.');
        } catch (ActivitiesRefused $refused) {
            self::assertSame('not_found', $refused->reason());
        }
    }
}
