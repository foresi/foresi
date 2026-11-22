<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Application;

use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\ActivityTargets;
use Foresi\Activities\Application\RecordLink;
use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivitySchedule;
use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Activities\Support\FakeActivityTarget;
use PHPUnit\Framework\TestCase;

/**
 * The records activities can be about: what a link must be, what the kinds
 * offered are, and who sees an activity through its links.
 */
final class ActivityTargetsTest extends TestCase
{
    private FakeActivityTarget $contacts;
    private FakeActivityTarget $deals;
    private string $contactId;

    protected function setUp(): void
    {
        $this->contactId = Id::generate();
        $this->contacts = new FakeActivityTarget('contact');
        $this->contacts->add($this->contactId, 'Example Client Ltd');
        $this->deals = new FakeActivityTarget('deal');
    }

    public function testALinkIsAnObjectWithAStringKindAndAValidId(): void
    {
        foreach ([
            'not an object' => 'contact',
            'no kind' => ['id' => $this->contactId],
            'a kind that is not a string' => ['kind' => 1, 'id' => $this->contactId],
            'no id' => ['kind' => 'contact'],
            'an id that is not a string' => ['kind' => 'contact', 'id' => 7],
            'an id that is not an id' => ['kind' => 'contact', 'id' => 'nope'],
        ] as $name => $link) {
            try {
                $this->targets($this->contacts)->check([$link]);
                self::fail($name.' is refused.');
            } catch (ActivitiesRefused $refused) {
                self::assertSame('link_invalid', $refused->reason(), $name);
                self::assertSame('Link 1 is not an object {kind, id} with a valid id.', $refused->getMessage(), $name);
            }
        }
    }

    public function testAKindNoModuleOffersIsRefusedWithTheKindsOffered(): void
    {
        try {
            $this->targets($this->deals, $this->contacts)->check([['kind' => 'ghost', 'id' => $this->contactId]]);
            self::fail('Refused.');
        } catch (ActivitiesRefused $refused) {
            self::assertSame('link_kind_unknown', $refused->reason());
            self::assertSame('Activities cannot link to records of kind "ghost"; the kinds offered are: contact, deal.', $refused->getMessage());
            self::assertSame(['kind' => 'ghost'], $refused->details());
        }
        try {
            new ActivityTargets([])->check([['kind' => 'ghost', 'id' => $this->contactId]]);
            self::fail('Refused.');
        } catch (ActivitiesRefused $refused) {
            self::assertSame('Activities cannot link to records of kind "ghost"; the kinds offered are: none.', $refused->getMessage());
        }
    }

    public function testARecordThePersonMayNotLinkToIsRefused(): void
    {
        $other = Id::generate();

        try {
            $this->targets($this->contacts)->check([['kind' => 'contact', 'id' => $other]]);
            self::fail('Refused.');
        } catch (ActivitiesRefused $refused) {
            self::assertSame('link_target_unknown', $refused->reason());
            self::assertSame(['kind' => 'contact', 'id' => $other], $refused->details());
        }
    }

    public function testALinkGivenTwiceCountsOnceAndTheResultIsAList(): void
    {
        $dealId = $this->contactId;
        $this->deals->add($dealId, 'Audit');
        $second = Id::generate();
        $this->contacts->add($second, 'Example Partner Ltd');

        $links = $this->targets($this->contacts, $this->deals)->check([
            ['kind' => 'contact', 'id' => $this->contactId],
            ['kind' => 'deal', 'id' => $dealId],
            ['kind' => 'contact', 'id' => $this->contactId],
            ['kind' => 'contact', 'id' => $second],
        ]);

        self::assertSame([0, 1, 2], array_keys($links), 'The same id of two kinds, and two ids of one kind, are different links.');
        self::assertEquals([new RecordLink('contact', $this->contactId), new RecordLink('deal', $dealId), new RecordLink('contact', $second)], $links);
    }

    public function testTheKindsOfferedAreSortedAndEachOnce(): void
    {
        $more = new FakeActivityTarget('contact');

        self::assertSame(['contact', 'deal'], $this->targets($this->deals, $this->contacts, $more)->kinds());
        self::assertSame(['contact', 'deal'], $this->targets($this->contacts, $more, $this->deals)->kinds());
        self::assertSame([], new ActivityTargets([])->kinds());
    }

    public function testAnActivityOfAKindNoModuleOffersIsSeenByItsOwnerOnly(): void
    {
        $activity = $this->activity();
        $ghost = [new RecordLink('ghost', $this->contactId)];
        $targets = $this->targets($this->contacts);

        self::assertFalse($targets->canSee($activity, $ghost, 'someone'));
        self::assertFalse($targets->canSee($activity, $ghost, null));
        self::assertTrue($targets->canSee($activity, $ghost, 'owner'));
        self::assertTrue($targets->canSee($activity, [new RecordLink('contact', $this->contactId)], 'someone'));
    }

    public function testALinkShowsNothingOfARecordNoModuleOffersOrThePersonMayNotSee(): void
    {
        $targets = $this->targets($this->contacts);

        $view = $targets->describe(new RecordLink('ghost', $this->contactId));
        self::assertSame([false, null, null], [$view->available, $view->label, $view->url]);
        $view = $targets->describe(new RecordLink('contact', Id::generate()));
        self::assertFalse($view->available);
        $view = $targets->describe(new RecordLink('contact', $this->contactId));
        self::assertSame([true, 'Example Client Ltd', '/contacts/'.$this->contactId], [$view->available, $view->label, $view->url]);
    }

    private function targets(FakeActivityTarget ...$targets): ActivityTargets
    {
        return new ActivityTargets($targets);
    }

    private function activity(): Activity
    {
        $now = new \DateTimeImmutable('2026-11-22 10:00:00 UTC');

        return new Activity(new ActivityType('note', null, ActivityTiming::None, null, $now), 'Kick-off', '', ActivitySchedule::none(), null, null, 'owner', 'owner', $now);
    }
}
