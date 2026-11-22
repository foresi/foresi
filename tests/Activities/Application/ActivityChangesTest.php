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
use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Activities\Support\ActivitiesTestCase;

/**
 * What changing an activity or a type does: done as it is logged, a date and
 * a time that replace one another, the trash that freezes, a type that is
 * unknown or has an unknown timing.
 */
final class ActivityChangesTest extends ActivitiesTestCase
{
    public function testAnActivityIsDoneWhenLoggedDoneOrGivenADoneTime(): void
    {
        $type = $this->type('meeting')->id();
        $links = [['kind' => 'contact', 'id' => $this->contactId]];

        $now = $this->activities()->create(new CreateActivityInput($type, 'Call just made', done: true, links: $links));
        $given = $this->activities()->create(new CreateActivityInput($type, 'Call made on Friday', doneAt: '2026-11-20T10:00:00+00:00', links: $links));
        $planned = $this->activities()->create(new CreateActivityInput($type, 'Call to make', plannedAt: '2026-11-25T10:00:00+00:00', links: $links));

        self::assertSame('2026-11-22 10:00:00', $now->doneAt()?->format('Y-m-d H:i:s'), 'Done without a time is done now.');
        self::assertSame('2026-11-20 10:00:00', $given->doneAt()?->format('Y-m-d H:i:s'), 'A done time makes it done.');
        self::assertFalse($planned->isDone());
    }

    public function testADateGivenClearsTheTimeAndTheOtherWayRound(): void
    {
        $task = $this->planned('Send the NDA', '2026-11-24', type: 'task');
        $meeting = $this->type('meeting');

        $changed = $this->activities()->update(new UpdateActivityInput($task->id(), typeId: $meeting->id(), plannedAt: '2026-11-25T10:00:00+00:00'));
        self::assertSame([null, '2026-11-25 10:00'], [$changed->plannedOn(), $changed->plannedAt()?->format('Y-m-d H:i')], 'A time given clears the date.');

        $back = $this->activities()->update(new UpdateActivityInput($task->id(), typeId: $this->type('task')->id(), plannedOn: '2026-11-26'));
        self::assertSame(['2026-11-26', null], [$back->plannedOn()?->format('Y-m-d'), $back->plannedAt()], 'A date given clears the time.');
    }

    public function testWhatIsNotGivenStaysWhenAnActivityIsChanged(): void
    {
        $task = $this->planned('Send the NDA', '2026-11-24', type: 'task');
        $meeting = $this->planned('Kick-off meeting', '2026-11-25T10:00:00+00:00');

        $task = $this->activities()->update(new UpdateActivityInput($task->id(), subject: 'Send the signed NDA'));
        $meeting = $this->activities()->update(new UpdateActivityInput($meeting->id(), subject: 'Kick-off call'));

        self::assertSame(['Send the signed NDA', '2026-11-24', null], [$task->subject(), $task->plannedOn()?->format('Y-m-d'), $task->plannedAt()]);
        self::assertSame(['Kick-off call', null, '2026-11-25 10:00'], [$meeting->subject(), $meeting->plannedOn(), $meeting->plannedAt()?->format('Y-m-d H:i')]);
    }

    public function testAnActivityInTheTrashIsRefusedAsTrashedNotAsUnknown(): void
    {
        $meeting = $this->planned('Kick-off meeting');
        $this->activities()->trash(new TrashActivityInput($meeting->id()));

        $this->assertConflict('trashed', fn (): \Foresi\Activities\Domain\Activity => $this->activities()->update(new UpdateActivityInput($meeting->id(), subject: 'Renamed')));
        $this->assertConflict('trashed', fn (): array => $this->activities()->complete(new CompleteActivityInput($meeting->id())));
        self::assertTrue($this->activities()->trash(new TrashActivityInput($meeting->id()))->isTrashed(), 'Trashing it again changes nothing.');
        self::assertFalse($this->activities()->restore(new RestoreActivityInput($meeting->id()))->isTrashed());
        self::assertFalse($this->activities()->restore(new RestoreActivityInput($meeting->id()))->isTrashed(), 'Restoring it again changes nothing.');
    }

    public function testATypeIsFoundOrRefused(): void
    {
        foreach ([
            fn (): \Foresi\Activities\Domain\ActivityType => $this->types()->update(new UpdateActivityTypeInput(Id::generate(), name: 'Renamed')),
            fn (): \Foresi\Activities\Domain\ActivityType => $this->types()->archive(new ArchiveActivityTypeInput(Id::generate())),
        ] as $use) {
            try {
                $use();
                self::fail('Not found.');
            } catch (ActivitiesRefused $refused) {
                self::assertSame('not_found', $refused->reason());
            }
        }
        try {
            $this->types()->create(new CreateActivityTypeInput('Site visit', 'site_visit', 'sometimes'));
            self::fail('Refused.');
        } catch (ActivitiesRefused $refused) {
            self::assertSame(['timing_unknown', 'timing'], [$refused->reason(), $refused->field()]);
            self::assertSame('The timing is timed, date or none.', $refused->getMessage());
        }
    }

    public function testATypeChangesItsTiming(): void
    {
        $type = $this->types()->create(new CreateActivityTypeInput('Site visit', 'site_visit', 'timed', 90));

        $kept = $this->types()->update(new UpdateActivityTypeInput($type->id(), name: 'Visit'));
        self::assertSame([ActivityTiming::Timed, 90, 'Visit'], [$kept->timing(), $kept->defaultDurationMinutes(), $kept->name()]);

        $changed = $this->types()->update(new UpdateActivityTypeInput($type->id(), timing: 'date'));
        self::assertSame([ActivityTiming::Date, null], [$changed->timing(), $changed->defaultDurationMinutes()]);
    }

    /**
     * @param callable(): mixed $use
     */
    private function assertConflict(string $reason, callable $use): void
    {
        try {
            $use();
            self::fail('Refused.');
        } catch (ActivitiesRefused $refused) {
            self::assertSame($reason, $refused->reason());
        }
    }
}
