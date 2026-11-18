<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Identity\Domain\Membership;
use Foresi\Shared\Domain\Id;

/**
 * A person a comment mentions, kept in step with its text so whom to notify
 * is a query. A row of its own, audited under the task of the comment.
 */
#[ORM\Entity]
#[ORM\Table(name: 'comment_mention')]
#[ORM\Index(name: 'idx_comment_mention_membership', columns: ['membership_id'])]
#[ORM\UniqueConstraint(name: 'uniq_comment_mention', columns: ['comment_id', 'membership_id'])]
class CommentMention implements Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

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
}
