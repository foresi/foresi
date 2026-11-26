<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Id;

/**
 * One meeting, of a series or a one-off: its title, the day it is held on and
 * optionally the time it starts in the instance's zone, and its minutes in
 * Markdown. The version rises with each save of the minutes, so a save made from
 * an older version is refused rather than merged. Its attendees and the records
 * it is about are rows of their own. It goes to the trash and back, as a task
 * does, and is purged 30 days after.
 */
#[ORM\Entity]
#[ORM\Table(name: 'meetings_meeting')]
#[ORM\Index(name: 'idx_meetings_meeting_held_on', columns: ['held_on'])]
#[ORM\Index(name: 'idx_meetings_meeting_series', columns: ['series_id'])]
class Meeting implements Audited
{
    public const int MAX_TITLE = 200;
    public const int MAX_MINUTES = 200000;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: MeetingSeries::class)]
    #[ORM\JoinColumn(name: 'series_id', nullable: true, onDelete: 'RESTRICT')]
    private ?MeetingSeries $series;

    #[ORM\Column(type: 'string', length: self::MAX_TITLE)]
    private string $title;

    #[ORM\Column(name: 'held_on', type: 'date_immutable')]
    private \DateTimeImmutable $heldOn;

    /** The time of the day it starts, "HH:MM" in the instance's zone, or null for none. */
    #[ORM\Column(name: 'starts_at', type: 'string', length: 5, nullable: true)]
    private ?string $startsAt;

    #[ORM\Column(type: 'text')]
    private string $minutes;

    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(name: 'created_by', type: 'guid')]
    private string $createdBy;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(name: 'deleted_by', type: 'guid', nullable: true)]
    private ?string $deletedBy = null;

    public function __construct(?MeetingSeries $series, string $title, \DateTimeImmutable $heldOn, ?string $startsAt, string $minutes, string $createdBy, \DateTimeImmutable $now)
    {
        $this->id = Id::generate();
        $this->series = $series;
        $this->title = $title;
        $this->heldOn = $heldOn;
        $this->startsAt = $startsAt;
        $this->minutes = $minutes;
        $this->createdBy = $createdBy;
        $this->createdAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function series(): ?MeetingSeries
    {
        return $this->series;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function heldOn(): \DateTimeImmutable
    {
        return $this->heldOn;
    }

    public function startsAt(): ?string
    {
        return $this->startsAt;
    }

    public function minutes(): string
    {
        return $this->minutes;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function createdBy(): string
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function deletedBy(): ?string
    {
        return $this->deletedBy;
    }

    public function isTrashed(): bool
    {
        return null !== $this->deletedAt;
    }

    public function reschedule(string $title, \DateTimeImmutable $heldOn, ?string $startsAt): void
    {
        $this->title = $title;
        $this->heldOn = $heldOn;
        $this->startsAt = $startsAt;
    }

    /**
     * Saves the minutes when they were read at the current version.
     *
     * @return bool whether the text changed; the version rises only then
     *
     * @throws StaleMinutes when someone saved the minutes since $readVersion
     */
    public function writeMinutes(string $minutes, int $readVersion): bool
    {
        if ($readVersion !== $this->version) {
            throw new StaleMinutes($this->version);
        }
        if ($minutes === $this->minutes) {
            return false;
        }
        $this->minutes = $minutes;
        ++$this->version;

        return true;
    }

    /**
     * @return bool whether it was not in the trash before
     */
    public function trash(string $userId, \DateTimeImmutable $now): bool
    {
        if (null !== $this->deletedAt) {
            return false;
        }
        $this->deletedAt = $now;
        $this->deletedBy = $userId;

        return true;
    }

    /**
     * @return bool whether it was in the trash
     */
    public function restore(): bool
    {
        if (null === $this->deletedAt) {
            return false;
        }
        $this->deletedAt = null;
        $this->deletedBy = null;

        return true;
    }
}
