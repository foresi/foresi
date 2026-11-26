<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Attachment\Application;

/**
 * An attachment access that also answers for a given person instead of the
 * signed-in one, for a job that files a file on behalf of a person while nobody
 * is signed in (UploadAttachment::uploadFor). A module that does not implement
 * it takes no such upload: its kind is denied.
 */
interface UserAttachmentAccess extends AttachmentAccess
{
    public function canSeeAs(string $userId, string $ownerKind, string $ownerId): bool;

    public function canAttachAs(string $userId, string $ownerKind, string $ownerId): bool;
}
