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
 * A record a meeting is about, by its kind and id, such as a project or a
 * contact. The record may go away; the link stays and shows nothing. Audited
 * under the meeting.
 */
#[ORM\Entity]
#[ORM\Table(name: 'meetings_link')]
#[ORM\Index(name: 'idx_meetings_link_target', columns: ['kind', 'target_id'])]
#[ORM\UniqueConstraint(name: 'uniq_meetings_link', columns: ['meeting_id', 'kind', 'target_id'])]
class MeetingLink implements Audited, AuditRooted
{
    public const int MAX_KIND = 40;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Meeting::class)]
        #[ORM\JoinColumn(name: 'meeting_id', nullable: false, onDelete: 'CASCADE')]
        private readonly Meeting $meeting,
        #[ORM\Column(type: 'string', length: self::MAX_KIND)]
        private readonly string $kind,
        #[ORM\Column(name: 'target_id', type: 'guid')]
        private readonly string $targetId,
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

    public function kind(): string
    {
        return $this->kind;
    }

    public function targetId(): string
    {
        return $this->targetId;
    }
}
