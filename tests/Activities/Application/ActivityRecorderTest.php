<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Application;

use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\ActivitiesStore;
use Foresi\Activities\Application\Api\TrashActivityInput;
use Foresi\Activities\Application\RecordsForOtherModules;
use Foresi\Activities\Contract\ActivityRecorder;
use Foresi\Activities\Contract\RecordedActivity;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Activities\Support\ActivitiesTestCase;

/**
 * What another module records through the contract: a done activity of a type by key, and a link
 * added to one that is there already.
 */
final class ActivityRecorderTest extends ActivitiesTestCase
{
    public function testRecordCreatesADoneEmailActivityWithItsLinksAndOwner(): void
    {
        $id = $this->recorder()->record($this->email('Re: Offer'));

        $activity = $this->reload($this->store()->activity($id) ?? throw new \LogicException());
        self::assertSame(['Re: Offer', 'Body of the mail.', '2026-11-20 09:30:00'], [$activity->subject(), $activity->body(), $activity->doneAt()?->format('Y-m-d H:i:s')]);
        self::assertSame($this->owner->user()->id(), $activity->ownerUserId());
        self::assertSame('email', $this->store()->type($activity->typeId())?->key());
        self::assertSame(['contact:'.$this->contactId], $this->linkNames($id));
        self::assertCount(1, $this->eventData('activities.activity.created'), 'The same event as the screens record.');
    }

    public function testLinkAddsARecordAndKeepsTheOthers(): void
    {
        $dealId = Id::generate();
        $this->deals->add($dealId, 'Example deal');
        $id = $this->recorder()->record($this->email('Re: Offer'));

        $this->recorder()->link($id, 'deal', $dealId);

        self::assertEqualsCanonicalizing(['contact:'.$this->contactId, 'deal:'.$dealId], $this->linkNames($id));
        $events = $this->eventData('activities.activity.updated');
        self::assertCount(1, $events);
        self::assertSame(['contact:'.$this->contactId, 'deal:'.$dealId], json_decode($events[0], true, flags: \JSON_THROW_ON_ERROR)['links'], 'The event names the records the activity has now: the old ones, then the new.');

        $otherDealId = Id::generate();
        $this->deals->add($otherDealId, 'Other deal');
        $this->recorder()->link($id, 'deal', $otherDealId);
        $events = $this->eventData('activities.activity.updated');
        self::assertSame(['contact:'.$this->contactId, 'deal:'.$dealId, 'deal:'.$otherDealId], json_decode($events[1], true, flags: \JSON_THROW_ON_ERROR)['links']);
    }

    public function testLinkingARecordTwiceChangesNothing(): void
    {
        $id = $this->recorder()->record($this->email('Re: Offer'));

        $this->recorder()->link($id, 'contact', $this->contactId);

        self::assertSame(['contact:'.$this->contactId], $this->linkNames($id));
        self::assertSame([], $this->eventData('activities.activity.updated'), 'No change, no event.');
    }

    public function testLinkRefusesAnUnknownKindAndARecordThatCannotBeLinked(): void
    {
        $id = $this->recorder()->record($this->email('Re: Offer'));

        $this->assertReason('link_kind_unknown', fn () => $this->recorder()->link($id, 'spaceship', Id::generate()));
        $this->assertReason('link_target_unknown', fn () => $this->recorder()->link($id, 'deal', Id::generate()));
        self::assertSame(['contact:'.$this->contactId], $this->linkNames($id));
    }

    public function testLinkRefusesAnUnknownOrTrashedActivity(): void
    {
        $this->assertReason('not_found', fn () => $this->recorder()->link(Id::generate(), 'contact', $this->contactId));

        $id = $this->recorder()->record($this->email('Re: Offer'));
        $this->activities()->trash(new TrashActivityInput($id));
        $this->assertReason('trashed', fn () => $this->recorder()->link($id, 'contact', $this->contactId));
    }

    public function testRecordAndLinkRefuseAnUnknownTypeAndAGuest(): void
    {
        $this->assertReason('type_unknown', fn (): string => $this->recorder()->record(new RecordedActivity('no_such_type', 'Subject', '', new \DateTimeImmutable('2026-11-20 09:30:00'), $this->owner->user()->id())));
        $recorded = $this->recorder()->record($this->email('Re: Offer'));

        $party = new Party(PartyKind::Person, 'Alex Example', null, null, '');
        $user = new User('guest@example.com', 'hash', 'Example Guest');
        $guest = new Membership($user, Role::Guest, $party);
        $this->em->persist($party);
        $this->em->persist($user);
        $this->em->persist($guest);
        $this->em->flush();
        $this->signIn($guest);
        $this->assertReason('forbidden', fn (): string => $this->recorder()->record($this->email('Re: Offer')));
        $this->assertReason('forbidden', fn () => $this->recorder()->link($recorded, 'contact', $this->contactId));
    }

    private function recorder(): ActivityRecorder
    {
        $container = self::getContainer();
        $this->type('email');

        return new RecordsForOtherModules($this->activities(), $container->get(ActivitiesStore::class));
    }

    private function email(string $subject): RecordedActivity
    {
        return new RecordedActivity('email', $subject, 'Body of the mail.', new \DateTimeImmutable('2026-11-20 09:30:00', new \DateTimeZone('UTC')), $this->owner->user()->id(), [['kind' => 'contact', 'id' => $this->contactId]]);
    }

    /**
     * @return list<string>
     */
    private function linkNames(string $activityId): array
    {
        $this->em->clear();
        $activity = $this->store()->activity($activityId) ?? throw new \LogicException();

        return array_map(static fn (\Foresi\Activities\Domain\ActivityLink $link): string => $link->kind().':'.$link->targetId(), $this->store()->links($activity));
    }

    private function assertReason(string $reason, \Closure $call): void
    {
        try {
            $call();
            self::fail('Expected the refusal '.$reason.'.');
        } catch (ActivitiesRefused $refused) {
            self::assertSame($reason, $refused->reason());
        }
    }
}
