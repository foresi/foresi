<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Application;

use App\Attachment\Domain\Attachment;

/**
 * Implemented by the module that owns a kind of record: who may see its
 * attachments, attach files to it and delete them. Every implementation is
 * found by its kinds; a kind no module claims is denied.
 */
interface AttachmentAccess
{
    /**
     * The owner kinds this module answers for, such as "task".
     *
     * @return list<string>
     */
    public function kinds(): array;

    public function canSee(string $ownerKind, string $ownerId): bool;

    public function canAttach(string $ownerKind, string $ownerId): bool;

    /**
     * Whether the person may delete an attachment they did not upload
     * themselves; the uploader always may while they can see the record.
     */
    public function canDelete(Attachment $attachment): bool;
}
