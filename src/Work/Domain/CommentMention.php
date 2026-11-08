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
 * A person a comment mentions, kept in step with its text so whom to notify
 * is a query. A row of its own, audited under the task of the comment.
 */
#[ORM\Entity]
#[ORM\Table(name: 'comment_mention')]
#[ORM\Index(name: 'idx_comment_mention_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_comment_mention_membership', columns: ['membership_id'])]
#[ORM\UniqueConstraint(name: 'uniq_comment_mention', columns: ['comment_id', 'membership_id'])]
class CommentMention implements TenantOwned, Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TaskComment::class)]
        #[ORM\JoinColumn(name: 'comment_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly TaskComment $comment,
        #[ORM\ManyToOne(targetEntity: Membership::class)]
        #[ORM\JoinColumn(name: 'membership_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly Membership $membership,
    ) {
        $this->id = Id::generate();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->comment->task();
    }

    public function comment(): TaskComment
    {
        return $this->comment;
    }

    public function membership(): Membership
    {
        return $this->membership;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
