<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Domain;

/**
 * When an activity is planned: on a date, or at a time with a duration, never
 * both, or not at all for one that is only logged as done.
 */
final readonly class ActivitySchedule
{
    /**
     * @param \DateTimeImmutable|null $plannedOn       the day, its time ignored
     * @param \DateTimeImmutable|null $plannedAt       the start
     * @param int|null                $durationMinutes for a timed type; null takes the type's default
     *
     * @throws InvalidField
     */
    public function __construct(
        public ?\DateTimeImmutable $plannedOn = null,
        public ?\DateTimeImmutable $plannedAt = null,
        public ?int $durationMinutes = null,
    ) {
        if ($plannedOn instanceof \DateTimeImmutable && $plannedAt instanceof \DateTimeImmutable) {
            throw new InvalidField('plannedAt', 'An activity is planned on a date or at a time, not both.');
        }
        if (null !== $durationMinutes && ($durationMinutes < 1 || $durationMinutes > ActivityType::MAX_DURATION)) {
            throw new InvalidField('durationMinutes', \sprintf('A duration is 1 to %d minutes.', ActivityType::MAX_DURATION));
        }
    }

    public static function none(): self
    {
        return new self();
    }

    public function isPlanned(): bool
    {
        return $this->plannedOn instanceof \DateTimeImmutable || $this->plannedAt instanceof \DateTimeImmutable;
    }

    /**
     * Whether the type has this kind of time: a time for a timed type, a date
     * for a dated one, none for a note.
     *
     * @throws InvalidField
     */
    public function checkFor(ActivityType $type): void
    {
        $timing = $type->timing();
        if (ActivityTiming::Timed !== $timing && $this->plannedAt instanceof \DateTimeImmutable) {
            throw new InvalidField('plannedAt', ActivityTiming::Date === $timing ? 'An activity of this type is planned on a date, not at a time.' : 'An activity of this type has no time; it is done when it is written.');
        }
        if (ActivityTiming::Date !== $timing && $this->plannedOn instanceof \DateTimeImmutable) {
            throw new InvalidField('plannedOn', ActivityTiming::Timed === $timing ? 'An activity of this type is planned at a time, not on a date.' : 'An activity of this type has no time; it is done when it is written.');
        }
    }
}
