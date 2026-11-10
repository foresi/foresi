<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Domain;

use App\Audit\Domain\Audited;
use App\Identity\Domain\Membership;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * A piece of work in a project, its own audit root: comments, attachments and
 * time will hang off it by id. One person is responsible; others follow it as
 * watchers. A subtask sits one level under a task of the same project and
 * takes nothing else from it.
 *
 * One position orders the task both in its section in the list and in its
 * status column on the board. Both views show subsets of the project's tasks,
 * and any subset of one total order is ordered consistently, so moving a task
 * on the board never shuffles the list behind the team's back, and no second
 * order has to be kept in step.
 *
 * The parent is set when the task is created and does not change: the domain
 * cannot see whether a task has subtasks of its own, so turning a task into a
 * subtask is left to the application, which can.
 *
 * A task in the trash keeps everything, its position included, and comes back
 * as it was; the application puts its subtasks there with it and hides it from
 * every view until it is restored or purged for good.
 *
 * A private task is hidden from everyone it is not shared with, even from
 * those who see its project and section; its subtasks follow it.
 *
 * The estimate is the time the work should take, in minutes, set against the
 * time logged on the task (Teamwork); a subtask has one of its own.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task')]
#[ORM\Index(name: 'idx_task_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_task_project_position', columns: ['tenant_id', 'project_id', 'position'])]
#[ORM\Index(name: 'idx_task_responsible_due', columns: ['tenant_id', 'responsible_id', 'due_on'])]
#[ORM\Index(name: 'idx_task_project', columns: ['project_id'])]
#[ORM\Index(name: 'idx_task_section', columns: ['section_id'])]
#[ORM\Index(name: 'idx_task_parent', columns: ['parent_id'])]
#[ORM\Index(name: 'idx_task_status', columns: ['status_id'])]
#[ORM\Index(name: 'idx_task_responsible', columns: ['responsible_id'])]
#[ORM\Index(name: 'idx_task_deleted', columns: ['tenant_id', 'deleted_at'])]
class Task implements TenantOwned, Audited
{
    public const int MAX_TITLE_LENGTH = 500;
    /** 9,999 hours: more is no estimate. */
    public const int MAX_ESTIMATE_MINUTES = 9_999 * 60;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: self::MAX_TITLE_LENGTH)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $description = '';

    #[ORM\ManyToOne(targetEntity: Section::class)]
    #[ORM\JoinColumn(name: 'section_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Section $section;

    #[ORM\ManyToOne(targetEntity: ProjectStatus::class)]
    #[ORM\JoinColumn(name: 'status_id', nullable: false, onDelete: 'RESTRICT')]
    private ProjectStatus $status;

    #[ORM\ManyToOne(targetEntity: Membership::class)]
    #[ORM\JoinColumn(name: 'responsible_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Membership $responsible = null;

    #[ORM\Column(name: 'due_on', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dueOn = null;

    #[ORM\Column(type: 'string', length: 16, enumType: Priority::class)]
    private Priority $priority = Priority::None;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $private = false;

    #[ORM\Column(name: 'estimate_minutes', type: 'integer', nullable: true)]
    private ?int $estimateMinutes = null;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /** The user who put the task in the trash. */
    #[ORM\Column(name: 'deleted_by', type: 'guid', nullable: true)]
    private ?string $deletedBy = null;

    /**
     * @throws \DomainException when the section, status or parent is of another project, or the parent is a subtask
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Project::class)]
        #[ORM\JoinColumn(name: 'project_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Project $project,
        string $title,
        ProjectStatus $status,
        #[ORM\Column(type: 'integer')]
        private int $position,
        #[ORM\Column(name: 'created_by', type: 'guid')]
        private readonly string $createdBy,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
        ?Section $section = null,
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'RESTRICT')]
        private readonly ?self $parent = null,
    ) {
        $this->id = Id::generate();
        if ($parent instanceof self) {
            if ($parent->isSubtask()) {
                throw new \DomainException('A subtask cannot have subtasks of its own.');
            }
            $this->assertSameProject($parent->project(), 'The parent task');
        }
        $this->rename($title);
        $this->moveToSection($section);
        $this->moveToStatus($status, $createdAt);
    }

    public function rename(string $title): void
    {
        $this->title = Name::of($title, self::MAX_TITLE_LENGTH, 'A task');
    }

    /**
     * Plain text for now; Markdown arrives later in the same column.
     */
    public function describe(string $description): void
    {
        $this->description = $description;
    }

    /**
     * Entering a finished status records when the task was completed; moving
     * between two finished ones keeps that date, and leaving them clears it.
     */
    public function moveToStatus(ProjectStatus $status, \DateTimeImmutable $now): void
    {
        $this->assertSameProject($status->project(), 'The status');
        if (!$status->type()->isFinished()) {
            $this->completedAt = null;
        } else {
            $this->completedAt ??= $now;
        }
        $this->status = $status;
    }

    public function moveToSection(?Section $section): void
    {
        if ($section instanceof Section) {
            $this->assertSameProject($section->project(), 'The section');
        }
        $this->section = $section;
    }

    public function assign(?Membership $responsible): void
    {
        $this->responsible = $responsible;
    }

    public function dueOn(?\DateTimeImmutable $dueOn): void
    {
        $this->dueOn = $dueOn?->setTime(0, 0);
    }

    public function prioritise(Priority $priority): void
    {
        $this->priority = $priority;
    }

    /**
     * @throws \DomainException when the estimate is not positive or longer than MAX_ESTIMATE_MINUTES
     */
    public function estimate(?int $minutes): void
    {
        if (null !== $minutes && ($minutes < 1 || $minutes > self::MAX_ESTIMATE_MINUTES)) {
            throw new \DomainException(sprintf('An estimate is between a minute and %d minutes.', self::MAX_ESTIMATE_MINUTES));
        }
        $this->estimateMinutes = $minutes;
    }

    public function estimateMinutes(): ?int
    {
        return $this->estimateMinutes;
    }

    public function makePrivate(bool $private): void
    {
        $this->private = $private;
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    /**
     * Deleting a task in the trash keeps the first date and person.
     */
    public function delete(string $userId, \DateTimeImmutable $now): void
    {
        if (!$this->isDeleted()) {
            $this->deletedAt = $now;
            $this->deletedBy = $userId;
        }
    }

    public function restore(): void
    {
        $this->deletedAt = null;
        $this->deletedBy = null;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt instanceof \DateTimeImmutable;
    }

    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function deletedBy(): ?string
    {
        return $this->deletedBy;
    }

    public function isSubtask(): bool
    {
        return $this->parent instanceof self;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function section(): ?Section
    {
        return $this->section;
    }

    public function parent(): ?self
    {
        return $this->parent;
    }

    public function status(): ProjectStatus
    {
        return $this->status;
    }

    public function responsible(): ?Membership
    {
        return $this->responsible;
    }

    public function dueDate(): ?\DateTimeImmutable
    {
        return $this->dueOn;
    }

    public function priority(): Priority
    {
        return $this->priority;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function isCompleted(): bool
    {
        return $this->completedAt instanceof \DateTimeImmutable;
    }

    public function createdBy(): string
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    private function assertSameProject(Project $project, string $what): void
    {
        if ($project->id() !== $this->project->id()) {
            throw new \DomainException(sprintf('%s belongs to another project.', $what));
        }
    }
}
