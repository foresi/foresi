<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * A comment as the discussion shows it. A deleted one keeps its author and
 * date but shows no text and no files.
 */
final readonly class CommentView
{
    /**
     * @param string                  $text        the Markdown source, which its author edits; empty once deleted
     * @param string                  $html        the body rendered from Markdown, safe to print; empty once deleted
     * @param bool                    $canEdit     whether the signed-in person may edit it: its author, while it stands
     * @param bool                    $canDelete   whether the signed-in person may delete it: its author or an owner, while it stands
     * @param list<CommentAttachment> $attachments oldest first
     */
    public function __construct(
        public string $id,
        public string $authorName,
        public string $authorInitials,
        public string $text,
        public string $html,
        public bool $internal,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $editedAt,
        public bool $deleted,
        public bool $canEdit,
        public bool $canDelete,
        public array $attachments,
    ) {
    }
}
