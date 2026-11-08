<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Attachment;

use App\Attachment\Application\PurgeAttachments;
use App\Work\Application\TaskFiles;

/**
 * The files of tasks and comments in the Attachment module, under the kinds
 * TaskAttachmentAccess answers for.
 */
final readonly class AttachmentTaskFiles implements TaskFiles
{
    public function __construct(private PurgeAttachments $attachments)
    {
    }

    public function forget(array $taskIds, array $commentIds): array
    {
        return [
            ...$this->attachments->forget(TaskAttachmentAccess::KIND, $taskIds),
            ...$this->attachments->forget(TaskAttachmentAccess::COMMENT_KIND, $commentIds),
        ];
    }

    public function removeFiles(array $files): void
    {
        $this->attachments->removeFiles($files);
    }
}
