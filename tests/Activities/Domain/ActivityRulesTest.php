<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Domain;

use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivityConflict;
use Foresi\Activities\Domain\ActivitySchedule;
use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Activities\Domain\InvalidField;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

/**
 * What an activity allows: a date or a time as its type says, never both; a
 * note is done when it is written; a timed one takes its type's duration.
 */
final class ActivityRulesTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-22 10:00:00 UTC');
    }

    public function testPlannedOnAndPlannedAtAreExclusive(): void
    {
        try {
            new ActivitySchedule(new \DateTimeImmutable('2026-11-25'), new \DateTimeImmutable('2026-11-25 10:00'));
            self::fail('A date and a time together are refused.');
        } catch (InvalidField $invalid) {
            self::assertSame('plannedAt', $invalid->field);
        }
        $this->assertRefusedAt('plannedOn', fn (): Activity => $this->activity($this->type(ActivityTiming::Timed, 60), new ActivitySchedule(plannedOn: new \DateTimeImmutable('2026-11-25'))));
        $this->assertRefusedAt('plannedAt', fn (): Activity => $this->activity($this->type(ActivityTiming::Date), new ActivitySchedule(plannedAt: new \DateTimeImmutable('2026-11-25 10:00'))));
        $this->assertRefusedAt('plannedAt', fn (): Activity => $this->activity($this->type(ActivityTiming::None), new ActivitySchedule(plannedAt: new \DateTimeImmutable('2026-11-25 10:00'))));
        $this->assertRefusedAt('plannedOn', fn (): Activity => $this->activity($this->type(ActivityTiming::None), new ActivitySchedule(plannedOn: new \DateTimeImmutable('2026-11-25'))));

        $task = $this->activity($this->type(ActivityTiming::Date), new ActivitySchedule(plannedOn: new \DateTimeImmutable('2026-11-25')));
        self::assertSame(['2026-11-25', null, null, false], [$task->plannedOn()?->format('Y-m-d'), $task->plannedAt(), $task->durationMinutes(), $task->isDone()]);
        self::assertTrue($task->isPlanned());
    }

    public function testNoteTypeIsCreatedDone(): void
    {
        $note = $this->activity($this->type(ActivityTiming::None), ActivitySchedule::none());

        self::assertTrue($note->isDone());
        self::assertSame('2026-11-22 10:00:00', $note->doneAt()?->format('Y-m-d H:i:s'));
        self::assertFalse($note->isPlanned());
        self::assertNull($note->durationMinutes());
        $this->expectException(ActivityConflict::class);
        $note->complete($this->now, null, $this->now);
    }

    public function testTimedTypeUsesDefaultDuration(): void
    {
        $meeting = $this->type(ActivityTiming::Timed, 60);
        $prague = new \DateTimeImmutable('2026-11-25 10:00', new \DateTimeZone('Europe/Prague'));

        $planned = $this->activity($meeting, new ActivitySchedule(plannedAt: $prague));
        $short = $this->activity($meeting, new ActivitySchedule(plannedAt: $prague, durationMinutes: 30));

        self::assertSame([60, 30], [$planned->durationMinutes(), $short->durationMinutes()]);
        self::assertSame('2026-11-25T09:00:00+00:00', $planned->plannedAt()?->format(\DATE_ATOM), 'A time is kept in UTC.');
        self::assertNull($this->activity($this->type(ActivityTiming::Date), new ActivitySchedule(plannedOn: $prague, durationMinutes: 30))->durationMinutes(), 'Only a timed activity has a duration.');
    }

    public function testAPlannedOrLoggedActivityNeedsOneOfThem(): void
    {
        $this->assertRefusedAt('plannedAt', fn (): Activity => $this->activity($this->type(ActivityTiming::Timed, 60), ActivitySchedule::none()));
        $this->assertRefusedAt('plannedOn', fn (): Activity => $this->activity($this->type(ActivityTiming::Date), ActivitySchedule::none()));

        $logged = $this->activity($this->type(ActivityTiming::Timed, 15), ActivitySchedule::none(), new \DateTimeImmutable('2026-11-21 15:00 Europe/Prague'));
        self::assertSame(['2026-11-21 14:00', 15], [$logged->doneAt()?->format('Y-m-d H:i'), $logged->durationMinutes()]);
    }

    public function testFieldsAreCheckedAndTrimmed(): void
    {
        $type = $this->type(ActivityTiming::None);
        $this->assertRefusedAt('subject', fn (): Activity => $this->activity($type, ActivitySchedule::none(), subject: '  '));
        $this->assertRefusedAt('subject', fn (): Activity => $this->activity($type, ActivitySchedule::none(), subject: str_repeat('a', Activity::MAX_SUBJECT + 1)));
        $this->assertRefusedAt('body', fn (): Activity => $this->activity($type, ActivitySchedule::none(), body: str_repeat('a', Activity::MAX_BODY + 1)));
        $this->assertRefusedAt('outcome', fn (): Activity => $this->activity($type, ActivitySchedule::none(), outcome: str_repeat('a', Activity::MAX_OUTCOME + 1)));
        $this->assertRefusedAt('durationMinutes', static fn (): ActivitySchedule => new ActivitySchedule(durationMinutes: 0));
        $this->assertRefusedAt('durationMinutes', static fn (): ActivitySchedule => new ActivitySchedule(durationMinutes: ActivityType::MAX_DURATION + 1));

        $note = $this->activity($type, ActivitySchedule::none(), subject: str_repeat('a', Activity::MAX_SUBJECT), body: str_repeat('b', Activity::MAX_BODY), outcome: '  ');
        self::assertNull($note->outcome(), 'An empty outcome is none.');
        self::assertSame(1440, new ActivitySchedule(durationMinutes: 1440)->durationMinutes);
        self::assertSame(1, new ActivitySchedule(durationMinutes: 1)->durationMinutes);
        self::assertSame('Kick-off', $this->activity($type, ActivitySchedule::none(), subject: ' Kick-off ')->subject());
    }

    public function testCompletingTrashingAndRestoring(): void
    {
        $call = $this->activity($this->type(ActivityTiming::Timed, 15), new ActivitySchedule(plannedAt: $this->now->modify('+1 day')));
        $later = $this->now->modify('+2 hours');

        $call->complete($later, ' Agreed on an audit ', $later);
        self::assertSame([true, 'Agreed on an audit', $later], [$call->isDone(), $call->outcome(), $call->updatedAt()]);
        try {
            $call->complete($later, null, $later);
            self::fail('A done activity is not done again.');
        } catch (ActivityConflict $conflict) {
            self::assertSame('already_done', $conflict->reason);
        }

        self::assertTrue($call->trash('user', $later));
        self::assertFalse($call->trash('other', $later->modify('+1 hour')), 'Trashing it again changes nothing.');
        self::assertSame(['user', $later], [$call->trashedBy(), $call->trashedAt()]);
        try {
            $call->assignTo('other', $later);
            self::fail('An activity in the trash does not change.');
        } catch (ActivityConflict $conflict) {
            self::assertSame('trashed', $conflict->reason);
        }
        self::assertTrue($call->restore($later));
        self::assertFalse($call->restore($later));
        self::assertNull($call->trashedBy());
        $call->assignTo('other', $later);
        self::assertSame('other', $call->ownerUserId());
    }

    public function testATypeKeepsItsKeyAndADurationOnlyWhenTimed(): void
    {
        $this->assertRefusedAt('key', fn (): ActivityType => new ActivityType('Site visit', null, ActivityTiming::Timed, 30, $this->now));
        $this->assertRefusedAt('name', fn (): ActivityType => new ActivityType('visit', ' ', ActivityTiming::Timed, 30, $this->now));
        $this->assertRefusedAt('name', fn (): ActivityType => new ActivityType('visit', str_repeat('a', ActivityType::MAX_NAME + 1), ActivityTiming::Timed, 30, $this->now));
        $this->assertRefusedAt('defaultDurationMinutes', fn (): ActivityType => new ActivityType('visit', null, ActivityTiming::Timed, 0, $this->now));
        $this->assertRefusedAt('defaultDurationMinutes', fn (): ActivityType => new ActivityType('visit', null, ActivityTiming::Timed, ActivityType::MAX_DURATION + 1, $this->now));

        $type = new ActivityType('site_visit', ' Site visit ', ActivityTiming::Timed, ActivityType::MAX_DURATION, $this->now);
        self::assertSame(['site_visit', 'Site visit', 1440], [$type->key(), $type->name(), $type->defaultDurationMinutes()]);
        $type->change(null, ActivityTiming::Date, 30);
        self::assertSame([null, ActivityTiming::Date, null], [$type->name(), $type->timing(), $type->defaultDurationMinutes()]);
        $type->archive($this->now);
        $type->archive($this->now->modify('+1 day'));
        self::assertSame($this->now, $type->archivedAt());
    }

    /**
     * @param callable(): mixed $change
     */
    private function assertRefusedAt(string $field, callable $change): void
    {
        try {
            $change();
            self::fail(\sprintf('Refused at %s.', $field));
        } catch (InvalidField $invalid) {
            self::assertSame($field, $invalid->field);
        }
    }

    private function type(ActivityTiming $timing, ?int $duration = null): ActivityType
    {
        return new ActivityType('type_'.substr(Id::generate(), -6), null, $timing, $duration, $this->now);
    }

    private function activity(ActivityType $type, ActivitySchedule $schedule, ?\DateTimeImmutable $doneAt = null, string $subject = 'Kick-off', string $body = '', ?string $outcome = null): Activity
    {
        return new Activity($type, $subject, $body, $schedule, $doneAt, $outcome, 'owner', 'owner', $this->now);
    }
}
