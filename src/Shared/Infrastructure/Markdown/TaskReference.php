<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Markdown;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * "[text](task:UUID)" in Markdown: the text, and the task it refers to.
 */
final class TaskReference extends AbstractInline
{
    public function __construct(
        public readonly string $text,
        public readonly string $taskId,
    ) {
        parent::__construct();
    }
}
