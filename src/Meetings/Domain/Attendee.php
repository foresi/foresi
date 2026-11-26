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
 * A person of the firm (a membership) at a meeting. A row of its own, audited
 * under the meeting. People from outside the firm are linked contacts instead.
 */
#[ORM\Entity]
#[ORM\Table(name: 'meetings_attendee')]
#[ORM\Index(name: 'idx_meetings_attendee_membership', columns: ['membership_id'])]
#[ORM\UniqueConstraint(name: 'uniq_meetings_attendee', columns: ['meeting_id', 'membership_id'])]
class Attendee implements Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Meeting::class)]
        #[ORM\JoinColumn(name: 'meeting_id', nullable: false, onDelete: 'CASCADE')]
        private readonly Meeting $meeting,
        #[ORM\Column(name: 'membership_id', type: 'guid')]
        private readonly string $membershipId,
    ) {
        $this->id = Id::generate();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->meeting;
    }

    public function meeting(): Meeting
    {
        return $this->meeting;
    }

    public function membershipId(): string
    {
        return $this->membershipId;
    }
}
