<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Attachment;

use App\Attachment\Application\AttachmentAccess;
use App\Attachment\Domain\Attachment;
use App\Audit\Application\AuditActor;
use App\Identity\Application\OwnerCheck;
use App\Identity\Application\People;
use App\Identity\Domain\Membership;
use App\Work\Application\Comments;
use App\Work\Application\Tasks;
use App\Work\Application\WorkVisibility;
use App\Work\Domain\Task;
use App\Work\Domain\TaskComment;

/**
 * The files of a task: whoever may see the task sees them and attaches more,
 * as anyone who sees a task may change it; an owner of the organisation also
 * deletes those that others uploaded.
 *
 * The files of a comment are part of what its author said: they are seen with
 * the task while the comment stands, only the author attaches them, and an
 * owner may delete those of others, as the comment itself.
 */
final readonly class TaskAttachmentAccess implements AttachmentAccess
{
    public const string KIND = 'task';
    public const string COMMENT_KIND = 'task_comment';

    public function __construct(
        private Tasks $tasks,
        private Comments $comments,
        private WorkVisibility $visibility,
        private OwnerCheck $owner,
        private People $people,
        private AuditActor $actor,
    ) {
    }

    public function kinds(): array
    {
        return [self::KIND, self::COMMENT_KIND];
    }

    public function canSee(string $ownerKind, string $ownerId): bool
    {
        if (self::COMMENT_KIND === $ownerKind) {
            return $this->standingComment($ownerId) instanceof TaskComment;
        }
        $task = self::KIND === $ownerKind ? $this->tasks->task($ownerId) : null;

        return $task instanceof Task && $this->visibility->canSeeTask($task);
    }

    public function canAttach(string $ownerKind, string $ownerId): bool
    {
        if (self::COMMENT_KIND === $ownerKind) {
            $comment = $this->standingComment($ownerId);
            $userId = $this->actor->userId();
            $me = null === $userId ? null : $this->people->membershipOf($userId);

            return $comment instanceof TaskComment && $me instanceof Membership && $me->isActive() && $comment->author()->id() === $me->id();
        }

        return $this->canSee($ownerKind, $ownerId);
    }

    public function canDelete(Attachment $attachment): bool
    {
        return $this->canSee($attachment->ownerKind(), $attachment->ownerId()) && $this->visibility->canManage() && $this->owner->isOwner();
    }

    private function standingComment(string $id): ?TaskComment
    {
        $comment = $this->comments->comment($id);

        return $comment instanceof TaskComment && !$comment->isDeleted() && $this->visibility->canSeeTask($comment->task()) ? $comment : null;
    }
}
