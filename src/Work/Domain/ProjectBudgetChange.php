<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Shared\Domain\Id;

/**
 * One dated change of a project's time budget, plus or minus, such as "+8 h
 * approved by the client" (Harvest's budget in hours with the change log of
 * Primavera Unifier). The budget is the sum of its changes; a project without
 * one has no budget. Its source may be an activity linked to the project,
 * such as the e-mail that approved the hours. A wrong change is removed; the
 * audit trail, under the project, keeps it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'project_budget_change')]
#[ORM\Index(name: 'idx_project_budget_change_project', columns: ['project_id', 'effective_on'])]
class ProjectBudgetChange implements Audited, AuditRooted
{
    /** 999,999 minutes, some 16,666 hours, either way. */
    public const int MAX_MINUTES = 999_999;

    public const int MAX_NOTE_LENGTH = 500;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    /**
     * @throws \DomainException when the minutes are zero or out of range, or the note is too long
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Project::class)]
        #[ORM\JoinColumn(name: 'project_id', nullable: false, onDelete: 'CASCADE')]
        private readonly Project $project,
        #[ORM\Column(name: 'effective_on', type: 'date_immutable')]
        private readonly \DateTimeImmutable $effectiveOn,
        #[ORM\Column(type: 'integer')]
        private readonly int $minutes,
        #[ORM\Column(type: 'string', length: self::MAX_NOTE_LENGTH)]
        private readonly string $note,
        /** The activity the change comes from, such as an e-mail, linked to the project. */
        #[ORM\Column(name: 'source_activity_id', type: 'guid', nullable: true)]
        private readonly ?string $sourceActivityId,
        #[ORM\Column(name: 'created_by', type: 'guid')]
        private readonly string $createdBy,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        if (0 === $minutes || abs($minutes) > self::MAX_MINUTES) {
            throw new \DomainException(\sprintf('A budget change is between 1 and %d minutes, plus or minus.', self::MAX_MINUTES));
        }
        if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            throw new \DomainException(\sprintf('A note of a budget change has at most %d characters.', self::MAX_NOTE_LENGTH));
        }
        $this->id = Id::generate();
    }

    public function auditRoot(): object
    {
        return $this->project;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function effectiveOn(): \DateTimeImmutable
    {
        return $this->effectiveOn;
    }

    public function minutes(): int
    {
        return $this->minutes;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function sourceActivityId(): ?string
    {
        return $this->sourceActivityId;
    }

    public function createdBy(): string
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
