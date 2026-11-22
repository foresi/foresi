<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Id;

/**
 * A meeting, call, note or task about some records, either planned ahead (on a
 * date, or at a time with a duration, as its type says) or logged as done, or
 * both once a planned one is done. A note has no time and is done when it is
 * written. Its links are records of their own (ActivityLink), kept by the
 * store, since changes to collections of audited entities are not audited.
 *
 * Going to the trash keeps everything, and restoring brings it back as it was,
 * as a time entry of Work does.
 */
#[ORM\Entity]
#[ORM\Table(name: 'activities_activity')]
#[ORM\Index(name: 'idx_activities_activity_owner', columns: ['owner_user_id'])]
class Activity implements Audited
{
    public const int MAX_SUBJECT = 200;
    public const int MAX_BODY = 20000;
    public const int MAX_OUTCOME = 1000;
    public const string ATTACHMENT_KIND = 'activity';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'type_id', type: 'guid')]
    private string $typeId;

    #[ORM\Column(type: 'string', length: self::MAX_SUBJECT)]
    private string $subject;

    /** Markdown. */
    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(name: 'planned_on', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $plannedOn;

    /** In UTC. */
    #[ORM\Column(name: 'planned_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $plannedAt;

    #[ORM\Column(name: 'duration_minutes', type: 'integer', nullable: true)]
    private ?int $durationMinutes;

    #[ORM\Column(name: 'done_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $doneAt;

    #[ORM\Column(type: 'string', length: self::MAX_OUTCOME, nullable: true)]
    private ?string $outcome;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'trashed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $trashedAt = null;

    /** Who put it in the trash. */
    #[ORM\Column(name: 'trashed_by', type: 'guid', nullable: true)]
    private ?string $trashedBy = null;

    /**
     * @throws InvalidField
     */
    public function __construct(
        ActivityType $type,
        string $subject,
        string $body,
        ActivitySchedule $schedule,
        ?\DateTimeImmutable $doneAt,
        ?string $outcome,
        #[ORM\Column(name: 'owner_user_id', type: 'guid')]
        private string $ownerUserId,
        #[ORM\Column(name: 'created_by', type: 'guid')]
        private string $createdBy,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
        $this->doneAt = $doneAt instanceof \DateTimeImmutable ? $this->utc($doneAt) : null;
        $this->updatedAt = $createdAt;
        $this->apply($type, $subject, $body, $schedule, $outcome, $createdAt);
    }

    /**
     * Changes what it is and when; whether it is done does not change, but a
     * note is done once it is one.
     *
     * @throws InvalidField
     * @throws ActivityConflict trashed
     */
    public function change(ActivityType $type, string $subject, string $body, ActivitySchedule $schedule, ?string $outcome, \DateTimeImmutable $now): void
    {
        $this->requireNotTrashed();
        $this->apply($type, $subject, $body, $schedule, $outcome, $now);
        $this->updatedAt = $now;
    }

    /**
     * @throws ActivityConflict trashed
     */
    public function assignTo(string $ownerUserId, \DateTimeImmutable $now): void
    {
        $this->requireNotTrashed();
        $this->ownerUserId = $ownerUserId;
        $this->updatedAt = $now;
    }

    /**
     * Marks it done, with what came of it.
     *
     * @throws InvalidField
     * @throws ActivityConflict already_done, trashed
     */
    public function complete(\DateTimeImmutable $doneAt, ?string $outcome, \DateTimeImmutable $now): void
    {
        $this->requireNotTrashed();
        if ($this->isDone()) {
            throw new ActivityConflict('already_done', 'The activity is done already.');
        }
        $this->outcome = $this->cleanOutcome($outcome);
        $this->doneAt = $this->utc($doneAt);
        $this->updatedAt = $now;
    }

    /**
     * Puts it in the trash; trashing it again changes nothing.
     *
     * @return bool whether it went to the trash now
     */
    public function trash(string $userId, \DateTimeImmutable $now): bool
    {
        if ($this->isTrashed()) {
            return false;
        }
        $this->trashedAt = $now;
        $this->trashedBy = $userId;
        $this->updatedAt = $now;

        return true;
    }

    /**
     * @return bool whether it came back now
     */
    public function restore(\DateTimeImmutable $now): bool
    {
        if (!$this->isTrashed()) {
            return false;
        }
        $this->trashedAt = null;
        $this->trashedBy = null;
        $this->updatedAt = $now;

        return true;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function typeId(): string
    {
        return $this->typeId;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function plannedOn(): ?\DateTimeImmutable
    {
        return $this->plannedOn;
    }

    public function plannedAt(): ?\DateTimeImmutable
    {
        return $this->plannedAt;
    }

    public function durationMinutes(): ?int
    {
        return $this->durationMinutes;
    }

    public function isPlanned(): bool
    {
        return $this->plannedOn instanceof \DateTimeImmutable || $this->plannedAt instanceof \DateTimeImmutable;
    }

    public function doneAt(): ?\DateTimeImmutable
    {
        return $this->doneAt;
    }

    public function isDone(): bool
    {
        return $this->doneAt instanceof \DateTimeImmutable;
    }

    public function outcome(): ?string
    {
        return $this->outcome;
    }

    public function ownerUserId(): string
    {
        return $this->ownerUserId;
    }

    public function createdBy(): string
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isTrashed(): bool
    {
        return $this->trashedAt instanceof \DateTimeImmutable;
    }

    public function trashedAt(): ?\DateTimeImmutable
    {
        return $this->trashedAt;
    }

    public function trashedBy(): ?string
    {
        return $this->trashedBy;
    }

    /**
     * @throws InvalidField
     */
    private function apply(ActivityType $type, string $subject, string $body, ActivitySchedule $schedule, ?string $outcome, \DateTimeImmutable $now): void
    {
        $subject = trim($subject);
        if ('' === $subject || mb_strlen($subject) > self::MAX_SUBJECT) {
            throw new InvalidField('subject', \sprintf('A subject has 1 to %d characters.', self::MAX_SUBJECT));
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new InvalidField('body', \sprintf('The notes have at most %d characters.', self::MAX_BODY));
        }
        $schedule->checkFor($type);
        if (ActivityTiming::None === $type->timing()) {
            $this->doneAt ??= $this->utc($now);
        }
        if (!$schedule->isPlanned() && !$this->isDone()) {
            throw new InvalidField(ActivityTiming::Date === $type->timing() ? 'plannedOn' : 'plannedAt', 'An activity is planned ahead or logged as done.');
        }
        $this->typeId = $type->id();
        $this->subject = $subject;
        $this->body = $body;
        $this->plannedOn = $schedule->plannedOn;
        $this->plannedAt = $schedule->plannedAt instanceof \DateTimeImmutable ? $this->utc($schedule->plannedAt) : null;
        $this->durationMinutes = ActivityTiming::Timed === $type->timing() ? ($schedule->durationMinutes ?? $type->defaultDurationMinutes()) : null;
        $this->outcome = $this->cleanOutcome($outcome);
    }

    /**
     * @throws ActivityConflict trashed
     */
    private function requireNotTrashed(): void
    {
        if ($this->isTrashed()) {
            throw new ActivityConflict('trashed', 'The activity is in the trash; restore it before changing it.');
        }
    }

    /**
     * @throws InvalidField
     */
    private function cleanOutcome(?string $outcome): ?string
    {
        $outcome = null === $outcome ? null : trim($outcome);
        if (null !== $outcome && mb_strlen($outcome) > self::MAX_OUTCOME) {
            throw new InvalidField('outcome', \sprintf('An outcome has at most %d characters.', self::MAX_OUTCOME));
        }

        return '' === $outcome ? null : $outcome;
    }

    private function utc(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return $time->setTimezone(new \DateTimeZone('UTC'));
    }
}
