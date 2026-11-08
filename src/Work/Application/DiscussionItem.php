<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * One item of the discussion of a task: a comment or a step of its history,
 * exactly one of the two.
 */
final readonly class DiscussionItem
{
    private function __construct(
        public \DateTimeImmutable $occurredAt,
        public ?CommentView $comment,
        public ?TaskHistoryEntry $step,
    ) {
    }

    public static function comment(CommentView $comment): self
    {
        return new self($comment->createdAt, $comment, null);
    }

    public static function step(TaskHistoryEntry $step): self
    {
        return new self($step->occurredAt, null, $step);
    }

    public function isComment(): bool
    {
        return $this->comment instanceof CommentView;
    }
}
