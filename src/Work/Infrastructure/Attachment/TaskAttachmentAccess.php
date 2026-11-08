<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Attachment;

use App\Attachment\Application\AttachmentAccess;
use App\Attachment\Domain\Attachment;
use App\Identity\Application\OwnerCheck;
use App\Work\Application\Tasks;
use App\Work\Application\WorkVisibility;
use App\Work\Domain\Task;

/**
 * The files of a task: whoever may see the task sees them and attaches more,
 * as anyone who sees a task may change it; an owner of the organisation also
 * deletes those that others uploaded.
 */
final readonly class TaskAttachmentAccess implements AttachmentAccess
{
    public const string KIND = 'task';

    public function __construct(private Tasks $tasks, private WorkVisibility $visibility, private OwnerCheck $owner)
    {
    }

    public function kinds(): array
    {
        return [self::KIND];
    }

    public function canSee(string $ownerKind, string $ownerId): bool
    {
        $task = self::KIND === $ownerKind ? $this->tasks->task($ownerId) : null;

        return $task instanceof Task && $this->visibility->canSeeTask($task);
    }

    public function canAttach(string $ownerKind, string $ownerId): bool
    {
        return $this->canSee($ownerKind, $ownerId);
    }

    public function canDelete(Attachment $attachment): bool
    {
        return $this->canSee($attachment->ownerKind(), $attachment->ownerId()) && $this->visibility->canManage() && $this->owner->isOwner();
    }
}
