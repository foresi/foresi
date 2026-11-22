<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Application;

use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\Api\CompleteActivityInput;
use Foresi\Activities\Domain\Activity;
use Foresi\Tests\Activities\Support\ActivitiesTestCase;

/**
 * "Done & Schedule Next" (Odoo, Pipedrive): marking an activity done and
 * planning the next step happen in one transaction, both or neither.
 */
final class CompleteAndPlanNextTest extends ActivitiesTestCase
{
    public function testCompletesAndCreatesNextInOneTransaction(): void
    {
        $meeting = $this->planned('Kick-off meeting');

        [$done, $next] = $this->activities()->complete(new CompleteActivityInput(
            $meeting->id(),
            outcome: 'Agreed on an audit',
            nextTypeId: $this->type('task')->id(),
            nextSubject: 'Send the audit proposal',
            nextPlannedOn: '2026-11-27',
        ));

        self::assertInstanceOf(Activity::class, $next);
        $done = $this->reload($done);
        self::assertSame(['2026-11-22 10:00', 'Agreed on an audit'], [$done->doneAt()?->format('Y-m-d H:i'), $done->outcome()]);
        $next = $this->store()->activity($next->id());
        self::assertInstanceOf(Activity::class, $next);
        self::assertSame(['Send the audit proposal', '2026-11-27', false, $this->owner->user()->id()], [$next->subject(), $next->plannedOn()?->format('Y-m-d'), $next->isDone(), $next->ownerUserId()]);
        self::assertSame([['contact', $this->contactId]], array_map(static fn ($link): array => [$link->kind(), $link->targetId()], $this->store()->links($next)), 'The next one is about the same records.');
        self::assertCount(1, $this->eventData('activities.activity.done'));
        self::assertCount(2, $this->eventData('activities.activity.created'));
        self::assertStringContainsString('"type_key":"task"', $this->eventData('activities.activity.created')[1]);
    }

    public function testFailedNextRollsBackCompletion(): void
    {
        $meeting = $this->planned('Kick-off meeting');
        $task = $this->type('task')->id();

        try {
            // A task is planned on a day, not at a time.
            $this->activities()->complete(new CompleteActivityInput($meeting->id(), outcome: 'Agreed', nextTypeId: $task, nextSubject: 'Send the proposal', nextPlannedAt: '2026-11-27T10:00:00+01:00'));
            self::fail('The next activity is refused.');
        } catch (ActivitiesRefused $refused) {
            self::assertSame(['invalid', 'nextPlannedAt'], [$refused->reason(), $refused->field()]);
        }

        $this->em->clear();
        $meeting = $this->store()->activity($meeting->id());
        self::assertInstanceOf(Activity::class, $meeting);
        self::assertFalse($meeting->isDone(), 'Its completion is rolled back.');
        self::assertCount(1, $this->store()->linkedTo('contact', $this->contactId));
        self::assertSame([], $this->eventData('activities.activity.done'));
    }

    public function testTheNextOneIsCheckedAtItsOwnFields(): void
    {
        $meeting = $this->planned('Kick-off meeting');
        $complete = fn (CompleteActivityInput $input) => $this->activities()->complete($input);

        foreach ([
            'nextTypeId' => new CompleteActivityInput($meeting->id(), nextSubject: 'Follow up', nextPlannedOn: '2026-11-27'),
            'nextSubject' => new CompleteActivityInput($meeting->id(), nextTypeId: $this->type('task')->id(), nextPlannedOn: '2026-11-27'),
            'nextPlannedOn' => new CompleteActivityInput($meeting->id(), nextTypeId: $this->type('task')->id(), nextSubject: 'Follow up', nextPlannedOn: '27. 11.'),
            'nextDurationMinutes' => new CompleteActivityInput($meeting->id(), nextTypeId: $this->type('call')->id(), nextSubject: 'Call back', nextPlannedAt: '2026-11-27T10:00', nextDurationMinutes: 0),
        ] as $field => $input) {
            try {
                $complete($input);
                self::fail($field.' is refused.');
            } catch (ActivitiesRefused $refused) {
                self::assertSame($field, $refused->field(), $refused->getMessage());
            }
        }
        self::assertFalse($this->reload($meeting)->isDone());
    }

    public function testCompletingWithoutANextOneOrTwice(): void
    {
        $call = $this->planned('Call back', type: 'call');

        [$done, $next] = $this->activities()->complete(new CompleteActivityInput($call->id(), doneAt: '2026-11-22T09:30:00+01:00'));

        self::assertNull($next);
        self::assertSame('2026-11-22 08:30', $this->reload($done)->doneAt()?->format('Y-m-d H:i'));
        try {
            $this->activities()->complete(new CompleteActivityInput($call->id()));
            self::fail('Done already.');
        } catch (ActivitiesRefused $refused) {
            self::assertSame('already_done', $refused->reason());
        }
    }
}
