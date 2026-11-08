<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditRooted;
use App\Identity\Domain\Membership;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a person wrote on a task, in Markdown, audited under the task. An
 * internal comment is for the people of the organisation only; it is the
 * default, so nothing reaches a client by accident once clients read tasks.
 *
 * Deleting keeps the row, so the discussion shows that something was said
 * and removed, but clears the text from it. The audit log still holds what it
 * said: the log is the record of the organisation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'task_comment')]
#[ORM\Index(name: 'idx_task_comment_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_task_comment_task', columns: ['task_id', 'created_at'])]
#[ORM\Index(name: 'idx_task_comment_author', columns: ['author_id'])]
class TaskComment implements TenantOwned, Audited, AuditRooted
{
    public const int MAX_BODY_LENGTH = 20_000;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(name: 'edited_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /**
     * @throws \DomainException when the body is empty or too long
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Task::class)]
        #[ORM\JoinColumn(name: 'task_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Task $task,
        #[ORM\ManyToOne(targetEntity: Membership::class)]
        #[ORM\JoinColumn(name: 'author_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Membership $author,
        string $body,
        #[ORM\Column(type: 'boolean')]
        private readonly bool $internal,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
        $this->body = self::checkedBody($body);
    }

    /**
     * The trimmed body a comment keeps.
     *
     * @throws \DomainException when it is empty or longer than MAX_BODY_LENGTH characters
     */
    public static function checkedBody(string $body): string
    {
        $body = trim($body);
        if ('' === $body) {
            throw new \DomainException('A comment says something.');
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new \DomainException(sprintf('A comment has at most %d characters.', self::MAX_BODY_LENGTH));
        }

        return $body;
    }

    /**
     * Saving the same text again is not an edit.
     *
     * @throws \DomainException when the comment is deleted, or the body is empty or too long
     */
    public function edit(string $body, \DateTimeImmutable $now): void
    {
        if ($this->isDeleted()) {
            throw new \DomainException('A deleted comment cannot be edited.');
        }
        $body = self::checkedBody($body);
        if ($body !== $this->body) {
            $this->body = $body;
            $this->editedAt = $now;
        }
    }

    /**
     * Deleting twice keeps the first date.
     */
    public function delete(\DateTimeImmutable $now): void
    {
        if (!$this->isDeleted()) {
            $this->body = '';
            $this->deletedAt = $now;
        }
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt instanceof \DateTimeImmutable;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->task;
    }

    public function task(): Task
    {
        return $this->task;
    }

    public function author(): Membership
    {
        return $this->author;
    }

    /**
     * The Markdown source; empty once deleted.
     */
    public function text(): string
    {
        return $this->body;
    }

    public function isInternal(): bool
    {
        return $this->internal;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function editedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
