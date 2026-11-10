<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditRooted;
use App\Identity\Domain\Membership;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Time a person worked on a project, on one of its tasks or on none (spec P11:
 * time used to go on "bucket" tasks). Audited under the task when it has one,
 * under the project otherwise.
 *
 * An entry is logged with its minutes, optionally with the start and end of
 * the work, or started as a timer: a start and no end, and no minutes until it
 * is stopped. The start is stored, so a timer survives reloads and devices.
 * One person runs one timer at a time; while it runs, runningFor holds their
 * membership, and a unique index over it keeps a second one out. A null never
 * collides with another, so stopped entries do not count.
 *
 * The invoice line that billed an entry locks it (Teamwork). The entry keeps
 * the invoice, the line, the rate it was billed at and the minutes billed,
 * rounded as the invoice took them; its own minutes stay as worked.
 */
#[ORM\Entity]
#[ORM\Table(name: 'time_entry')]
#[ORM\Index(name: 'idx_time_entry_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_time_entry_project', columns: ['project_id', 'worked_on'])]
#[ORM\Index(name: 'idx_time_entry_task', columns: ['task_id'])]
#[ORM\Index(name: 'idx_time_entry_membership', columns: ['membership_id', 'worked_on'])]
#[ORM\Index(name: 'idx_time_entry_invoice', columns: ['invoice_id'])]
#[ORM\UniqueConstraint(name: 'uniq_time_entry_running', columns: ['running_for'])]
class TimeEntry implements TenantOwned, Audited, AuditRooted
{
    public const int MAX_NOTE_LENGTH = 2_000;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(name: 'task_id', nullable: true, onDelete: 'RESTRICT')]
    private ?Task $task;

    #[ORM\Column(name: 'worked_on', type: 'date_immutable')]
    private \DateTimeImmutable $workedOn;

    /** Null while the timer runs. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $minutes = null;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'ended_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: 'text')]
    private string $note;

    /** The membership whose timer this is while it runs, null otherwise. */
    #[ORM\Column(name: 'running_for', type: 'guid', nullable: true)]
    private ?string $runningFor = null;

    /** The invoice line that billed the entry. */
    #[ORM\Column(name: 'invoice_line_id', type: 'guid', nullable: true)]
    private ?string $invoiceLineId = null;

    /** The invoice of that line. */
    #[ORM\Column(name: 'invoice_id', type: 'guid', nullable: true)]
    private ?string $invoiceId = null;

    #[ORM\Column(name: 'invoiced_rate_minor', type: 'bigint', nullable: true)]
    private ?int $invoicedRateMinor = null;

    #[ORM\Column(name: 'invoiced_rate_currency', type: 'string', length: 3, nullable: true, enumType: Currency::class, options: ['fixed' => true])]
    private ?Currency $invoicedRateCurrency = null;

    /** The minutes billed: the minutes worked, rounded up as the invoice asked. */
    #[ORM\Column(name: 'invoiced_minutes', type: 'integer', nullable: true)]
    private ?int $invoicedMinutes = null;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: Project::class)]
        #[ORM\JoinColumn(name: 'project_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Project $project,
        ?Task $task,
        #[ORM\ManyToOne(targetEntity: Membership::class)]
        #[ORM\JoinColumn(name: 'membership_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Membership $membership,
        \DateTimeImmutable $workedOn,
        #[ORM\Column(type: 'boolean')]
        private bool $billable,
        string $note,
        #[ORM\Column(name: 'created_by', type: 'guid')]
        private readonly string $createdBy,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
        $this->moveToTask($task);
        $this->workedOn = $workedOn->setTime(0, 0);
        $this->note = self::checkedNote($note);
    }

    /**
     * Time worked, with its minutes and, when known, its start and end.
     *
     * @throws \DomainException when the task is of another project, the minutes are not positive, the note is too long or the end does not come after the start
     */
    public static function logged(
        Project $project,
        ?Task $task,
        Membership $membership,
        \DateTimeImmutable $workedOn,
        int $minutes,
        ?\DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $endedAt,
        string $note,
        bool $billable,
        string $createdBy,
        \DateTimeImmutable $now,
    ): self {
        $entry = new self($project, $task, $membership, $workedOn, $billable, $note, $createdBy, $now);
        $entry->time($minutes, $startedAt, $endedAt);

        return $entry;
    }

    /**
     * A timer running from the moment.
     *
     * @throws \DomainException when the task is of another project or the note is too long
     */
    public static function started(
        Project $project,
        ?Task $task,
        Membership $membership,
        \DateTimeImmutable $workedOn,
        \DateTimeImmutable $startedAt,
        string $note,
        bool $billable,
        string $createdBy,
    ): self {
        $entry = new self($project, $task, $membership, $workedOn, $billable, $note, $createdBy, $startedAt);
        $entry->startedAt = $startedAt;
        $entry->runningFor = $membership->id();

        return $entry;
    }

    /**
     * The trimmed note an entry keeps.
     *
     * @throws \DomainException when it is longer than MAX_NOTE_LENGTH characters
     */
    public static function checkedNote(string $note): string
    {
        $note = trim($note);
        if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            throw new \DomainException(sprintf('A note has at most %d characters.', self::MAX_NOTE_LENGTH));
        }

        return $note;
    }

    /**
     * Stops the timer: the minutes are what it ran, at least one. Stopping a
     * stopped entry changes nothing.
     */
    public function stop(\DateTimeImmutable $now): void
    {
        if (!$this->isRunning() || !$this->startedAt instanceof \DateTimeImmutable) {
            return;
        }
        $this->endedAt = max($now, $this->startedAt);
        $this->minutes = Duration::spanMinutes($this->startedAt, $now);
        $this->runningFor = null;
    }

    /**
     * Changes what the entry says; a running timer is stopped first, an
     * invoiced entry not at all.
     *
     * @throws \DomainException when the entry runs or is invoiced, the task is of another project, the minutes are not positive, the note is too long or the end does not come after the start
     */
    public function change(
        ?Task $task,
        \DateTimeImmutable $workedOn,
        int $minutes,
        ?\DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $endedAt,
        string $note,
        bool $billable,
    ): void {
        if ($this->isRunning()) {
            throw new \DomainException('A running timer is stopped before it is changed.');
        }
        if ($this->isInvoiced()) {
            throw new \DomainException('An invoiced entry cannot be changed.');
        }
        $note = self::checkedNote($note);
        $this->moveToTask($task);
        $this->time($minutes, $startedAt, $endedAt);
        $this->workedOn = $workedOn->setTime(0, 0);
        $this->note = $note;
        $this->billable = $billable;
    }

    /**
     * Billed on the line of the invoice, at the rate, for the minutes: those
     * worked or more, as rounding up makes them.
     *
     * @throws \DomainException when the timer runs, the entry is invoiced already or fewer minutes are billed than worked
     */
    public function invoice(string $invoiceId, string $lineId, Money $rate, int $minutes): void
    {
        if ($this->isRunning() || null === $this->minutes) {
            throw new \DomainException('A running timer is not invoiced.');
        }
        if ($this->isInvoiced()) {
            throw new \DomainException('The entry is invoiced already.');
        }
        if ($minutes < $this->minutes) {
            throw new \DomainException('Billed time is at least the time worked.');
        }
        $this->invoiceId = $invoiceId;
        $this->invoiceLineId = $lineId;
        $this->invoicedRateMinor = $rate->minor();
        $this->invoicedRateCurrency = $rate->currency();
        $this->invoicedMinutes = $minutes;
    }

    /**
     * Keeps the time on the project once its task is gone for good.
     */
    public function leaveTask(): void
    {
        $this->task = null;
    }

    public function isRunning(): bool
    {
        return null !== $this->runningFor;
    }

    public function isInvoiced(): bool
    {
        return null !== $this->invoiceLineId;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->task ?? $this->project;
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function task(): ?Task
    {
        return $this->task;
    }

    public function membership(): Membership
    {
        return $this->membership;
    }

    public function workedOn(): \DateTimeImmutable
    {
        return $this->workedOn;
    }

    /**
     * Null while the timer runs.
     */
    public function minutes(): ?int
    {
        return $this->minutes;
    }

    public function startedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function endedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function isBillable(): bool
    {
        return $this->billable;
    }

    public function invoiceLineId(): ?string
    {
        return $this->invoiceLineId;
    }

    public function invoiceId(): ?string
    {
        return $this->invoiceId;
    }

    public function invoicedRate(): ?Money
    {
        return null === $this->invoicedRateMinor || !$this->invoicedRateCurrency instanceof Currency ? null : Money::ofMinor($this->invoicedRateMinor, $this->invoicedRateCurrency);
    }

    public function invoicedMinutes(): ?int
    {
        return $this->invoicedMinutes;
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

    /**
     * @throws \DomainException when the task is of another project
     */
    private function moveToTask(?Task $task): void
    {
        if ($task instanceof Task && $task->project()->id() !== $this->project->id()) {
            throw new \DomainException('The task belongs to another project.');
        }
        $this->task = $task;
    }

    /**
     * @throws \DomainException when the minutes are not positive, only one of the start and end is given, or the end does not come after the start
     */
    private function time(int $minutes, ?\DateTimeImmutable $startedAt, ?\DateTimeImmutable $endedAt): void
    {
        if ($minutes < 1) {
            throw new \DomainException('Time worked is at least a minute.');
        }
        if (($startedAt instanceof \DateTimeImmutable) !== ($endedAt instanceof \DateTimeImmutable)) {
            throw new \DomainException('Work has both a start and an end, or neither.');
        }
        if ($startedAt instanceof \DateTimeImmutable && $endedAt <= $startedAt) {
            throw new \DomainException('The end of work comes after its start.');
        }
        $this->minutes = $minutes;
        $this->startedAt = $startedAt;
        $this->endedAt = $endedAt;
    }
}
