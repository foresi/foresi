<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Attachment;

use Foresi\Activities\Application\ActivitiesAccess;
use Foresi\Activities\Application\ActivitiesStore;
use Foresi\Activities\Application\ActivityReads;
use Foresi\Activities\Application\ActivityTargets;
use Foresi\Activities\Domain\Activity;
use Foresi\Attachment\Application\AttachmentAccess;
use Foresi\Attachment\Domain\Attachment;

/**
 * The files of an activity, such as the recording of a meeting, follow the
 * activity: whoever sees it sees, adds and deletes them. One in the trash
 * shows none.
 */
final readonly class ActivityAttachmentAccess implements AttachmentAccess
{
    public function __construct(
        private ActivitiesStore $store,
        private ActivitiesAccess $access,
        private ActivityTargets $targets,
        private ActivityReads $reads,
    ) {
    }

    public function kinds(): array
    {
        return [Activity::ATTACHMENT_KIND];
    }

    public function canSee(string $ownerKind, string $ownerId): bool
    {
        return $this->activity($ownerKind, $ownerId) instanceof Activity;
    }

    public function canAttach(string $ownerKind, string $ownerId): bool
    {
        return $this->canSee($ownerKind, $ownerId);
    }

    public function canDelete(Attachment $attachment): bool
    {
        return $this->canSee($attachment->ownerKind(), $attachment->ownerId());
    }

    private function activity(string $ownerKind, string $ownerId): ?Activity
    {
        if (Activity::ATTACHMENT_KIND !== $ownerKind || !$this->access->isStaff()) {
            return null;
        }
        $activity = $this->store->activity($ownerId);
        if (!$activity instanceof Activity || $activity->isTrashed()) {
            return null;
        }

        return $this->targets->canSee($activity, $this->reads->links($activity), $this->access->userId()) ? $activity : null;
    }
}
