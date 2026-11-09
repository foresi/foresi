<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Domain;

use App\Identity\Domain\Membership;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * What one person hears about one task: a row of their inbox. A person has one
 * open row per task, as ClickUp, GitHub and Linear group by issue; each new
 * event folds into it and makes it unread again, newest first. The row shows
 * the latest event and who did it; the earlier ones are in the task history.
 * A row marked done stays as history and a new event then opens a new row.
 *
 * Besides the latest kind, the row remembers whether any event folded into it
 * was a mention or an assignment, for the filters of the inbox, and whether
 * one of those is still unread, for the e-mail sent at once. A new event is
 * news not mailed yet.
 *
 * It lives in the Work context: it is about a task and only the visibility
 * rule of the work decides whether its recipient may see it. It is not
 * audited: it is the reading state of one person, not a record of the
 * organisation, and the events themselves are in the audit of the task.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification')]
#[ORM\Index(name: 'idx_notification_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_notification_inbox', columns: ['recipient_id', 'done_at', 'created_at'])]
#[ORM\Index(name: 'idx_notification_task', columns: ['recipient_id', 'task_id', 'done_at'])]
#[ORM\Index(name: 'idx_notification_of_task', columns: ['task_id'])]
#[ORM\Index(name: 'idx_notification_actor', columns: ['actor_id'])]
class Notification implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    /** How many events came since the row was last read. */
    #[ORM\Column(type: 'integer')]
    private int $events = 1;

    /** Whether an event since the row was last read is a mention or an assignment. */
    #[ORM\Column(name: 'direct_unread', type: 'boolean')]
    private bool $directUnread;

    #[ORM\Column(type: 'boolean')]
    private bool $mentioned;

    #[ORM\Column(type: 'boolean')]
    private bool $assigned;

    #[ORM\Column(name: 'read_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(name: 'done_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $doneAt = null;

    #[ORM\Column(name: 'emailed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $emailedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Membership::class)]
        #[ORM\JoinColumn(name: 'recipient_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Membership $recipient,
        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'task_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Task $task,
        #[ORM\Column(type: 'string', length: 16, enumType: NotificationKind::class)]
        private NotificationKind $kind,
        #[ORM\ManyToOne(targetEntity: Membership::class)]
        #[ORM\JoinColumn(name: 'actor_id', nullable: false, onDelete: 'RESTRICT')]
        private Membership $actor,
        /** The time of the latest event. */
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
        $this->directUnread = $kind->isDirect();
        $this->mentioned = NotificationKind::Mentioned === $kind;
        $this->assigned = NotificationKind::Assigned === $kind;
    }

    /**
     * Folds a new event into the row, which becomes unread again.
     *
     * @throws \DomainException when the row is done: a new event opens a new row
     */
    public function record(NotificationKind $kind, Membership $actor, \DateTimeImmutable $now): void
    {
        if ($this->isDone()) {
            throw new \DomainException('A done notification takes no new event.');
        }
        if ($this->isRead()) {
            $this->events = 1;
            $this->directUnread = $kind->isDirect();
        } else {
            ++$this->events;
            $this->directUnread = $this->directUnread || $kind->isDirect();
        }
        $this->kind = $kind;
        $this->actor = $actor;
        $this->createdAt = $now;
        $this->readAt = null;
        $this->emailedAt = null;
        $this->mentioned = $this->mentioned || NotificationKind::Mentioned === $kind;
        $this->assigned = $this->assigned || NotificationKind::Assigned === $kind;
    }

    /**
     * Reading a read row keeps the first moment.
     */
    public function markRead(\DateTimeImmutable $now): void
    {
        $this->readAt ??= $now;
    }

    /**
     * Done is read too, as in GitHub.
     */
    public function markDone(\DateTimeImmutable $now): void
    {
        $this->markRead($now);
        $this->doneAt ??= $now;
    }

    public function markUndone(): void
    {
        $this->doneAt = null;
    }

    public function markEmailed(\DateTimeImmutable $now): void
    {
        $this->emailedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function recipient(): Membership
    {
        return $this->recipient;
    }

    public function task(): Task
    {
        return $this->task;
    }

    /**
     * The latest event.
     */
    public function kind(): NotificationKind
    {
        return $this->kind;
    }

    /**
     * Who did the latest event.
     */
    public function actor(): Membership
    {
        return $this->actor;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function events(): int
    {
        return $this->events;
    }

    public function hasDirectUnread(): bool
    {
        return $this->directUnread && !$this->isRead();
    }

    public function wasMentioned(): bool
    {
        return $this->mentioned;
    }

    public function wasAssigned(): bool
    {
        return $this->assigned;
    }

    public function isRead(): bool
    {
        return $this->readAt instanceof \DateTimeImmutable;
    }

    public function readAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isDone(): bool
    {
        return $this->doneAt instanceof \DateTimeImmutable;
    }

    public function doneAt(): ?\DateTimeImmutable
    {
        return $this->doneAt;
    }

    public function emailedAt(): ?\DateTimeImmutable
    {
        return $this->emailedAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
