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
use PHPUnit\Framework\TestCase;

/**
 * The edges of what an activity and its type allow: lengths count characters,
 * not bytes, and the limit itself is allowed; the trash freezes an activity;
 * the refusals say what is wrong.
 */
final class ActivityLimitsTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-22 10:00:00 UTC');
    }

    public function testLengthsCountCharactersAndTheLimitIsAllowed(): void
    {
        $note = $this->activity(ActivityTiming::None, subject: str_repeat('č', Activity::MAX_SUBJECT), body: str_repeat('č', Activity::MAX_BODY), outcome: str_repeat('č', 1000));

        self::assertSame([Activity::MAX_SUBJECT, Activity::MAX_BODY, 1000], [mb_strlen($note->subject()), mb_strlen($note->body()), mb_strlen((string) $note->outcome())]);
        $this->assertRefusedAt('outcome', fn (): Activity => $this->activity(ActivityTiming::None, outcome: str_repeat('č', 1001)));
        $this->assertRefusedAt('subject', fn (): Activity => $this->activity(ActivityTiming::None, subject: str_repeat('č', Activity::MAX_SUBJECT + 1)));

        $type = new ActivityType('visit', str_repeat('č', ActivityType::MAX_NAME), ActivityTiming::Timed, 1, $this->now);
        self::assertSame([ActivityType::MAX_NAME, 1], [mb_strlen((string) $type->name()), $type->defaultDurationMinutes()]);
        $this->assertRefusedAt('name', fn (): ActivityType => new ActivityType('visit', str_repeat('č', ActivityType::MAX_NAME + 1), ActivityTiming::Timed, 1, $this->now));
    }

    public function testATypeKeyIsTheWholeIdentifier(): void
    {
        foreach (['visit now', 'visit!', 'a'.str_repeat('b', 40)] as $key) {
            $this->assertRefusedAt('key', fn (): ActivityType => new ActivityType($key, null, ActivityTiming::None, null, $this->now));
        }

        self::assertSame('a'.str_repeat('b', 39), new ActivityType('a'.str_repeat('b', 39), null, ActivityTiming::None, null, $this->now)->key());
    }

    public function testANoteStaysDoneWhenItIsChanged(): void
    {
        $note = $this->activity(ActivityTiming::None);
        $later = $this->now->modify('+3 days');

        $note->change($this->typeOf($note, ActivityTiming::None), 'Kick-off notes', '', ActivitySchedule::none(), null, $later);

        self::assertSame('2026-11-22 10:00:00', $note->doneAt()?->format('Y-m-d H:i:s'), 'Done when it was written, not when it was changed.');
        self::assertSame($later, $note->updatedAt());
    }

    public function testAnActivityInTheTrashDoesNotChangeOrComplete(): void
    {
        $task = $this->activity(ActivityTiming::Date, new ActivitySchedule(plannedOn: new \DateTimeImmutable('2026-11-25')));
        $task->trash('user', $this->now);

        foreach ([
            fn () => $task->change($this->typeOf($task, ActivityTiming::Date), 'Renamed', '', new ActivitySchedule(plannedOn: new \DateTimeImmutable('2026-11-26')), null, $this->now),
            fn () => $task->complete($this->now, null, $this->now),
        ] as $change) {
            try {
                $change();
                self::fail('Refused as trashed.');
            } catch (ActivityConflict $conflict) {
                self::assertSame('trashed', $conflict->reason);
            }
        }
        self::assertFalse($task->isDone());
        self::assertSame('Kick-off', $task->subject());
    }

    public function testRefusalsSayWhatIsWrong(): void
    {
        $date = $this->type(ActivityTiming::Date);
        $timed = $this->type(ActivityTiming::Timed, 30);
        $none = $this->type(ActivityTiming::None);
        $at = new \DateTimeImmutable('2026-11-25 10:00');
        $on = new \DateTimeImmutable('2026-11-25');

        self::assertSame('An activity of this type is planned on a date, not at a time.', $this->refusal(static fn () => new ActivitySchedule(plannedAt: $at)->checkFor($date)));
        self::assertSame('An activity of this type has no time; it is done when it is written.', $this->refusal(static fn () => new ActivitySchedule(plannedAt: $at)->checkFor($none)));
        self::assertSame('An activity of this type is planned at a time, not on a date.', $this->refusal(static fn () => new ActivitySchedule(plannedOn: $on)->checkFor($timed)));
        self::assertSame('An activity of this type has no time; it is done when it is written.', $this->refusal(static fn () => new ActivitySchedule(plannedOn: $on)->checkFor($none)));
    }

    /**
     * @param callable(): mixed $change
     */
    private function refusal(callable $change): string
    {
        try {
            $change();
        } catch (InvalidField $invalid) {
            return $invalid->getMessage();
        }
        self::fail('Refused.');
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
        return new ActivityType('type_one', null, $timing, $duration, $this->now);
    }

    private function typeOf(Activity $activity, ActivityTiming $timing): ActivityType
    {
        return $this->types[$activity->typeId()] ?? $this->type($timing);
    }

    /** @var array<string, ActivityType> */
    private array $types = [];

    private function activity(ActivityTiming $timing, ?ActivitySchedule $schedule = null, string $subject = 'Kick-off', string $body = '', ?string $outcome = null): Activity
    {
        $type = $this->type($timing);
        $activity = new Activity($type, $subject, $body, $schedule ?? ActivitySchedule::none(), null, $outcome, 'owner', 'owner', $this->now);
        $this->types[$activity->typeId()] = $type;

        return $activity;
    }
}
