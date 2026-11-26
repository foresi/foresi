<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Shared\Domain\Id;

/**
 * A person of the firm (a membership) who belongs to a series: they see it and
 * its meetings when it is for its members, and change it. A default attendee
 * starts as an attendee of each new meeting of the series. A row of its own, so a
 * change of the people is audited under the series, as a watcher is under a task.
 */
#[ORM\Entity]
#[ORM\Table(name: 'meetings_series_member')]
#[ORM\Index(name: 'idx_meetings_series_member_membership', columns: ['membership_id'])]
#[ORM\UniqueConstraint(name: 'uniq_meetings_series_member', columns: ['series_id', 'membership_id'])]
class SeriesMember implements Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: MeetingSeries::class)]
        #[ORM\JoinColumn(name: 'series_id', nullable: false, onDelete: 'CASCADE')]
        private readonly MeetingSeries $series,
        #[ORM\Column(name: 'membership_id', type: 'guid')]
        private readonly string $membershipId,
        #[ORM\Column(name: 'default_attendee', type: 'boolean')]
        private bool $defaultAttendee,
    ) {
        $this->id = Id::generate();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->series;
    }

    public function series(): MeetingSeries
    {
        return $this->series;
    }

    public function membershipId(): string
    {
        return $this->membershipId;
    }

    public function isDefaultAttendee(): bool
    {
        return $this->defaultAttendee;
    }

    public function attendByDefault(bool $defaultAttendee): void
    {
        $this->defaultAttendee = $defaultAttendee;
    }
}
