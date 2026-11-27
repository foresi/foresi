<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Markdown;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/**
 * A reference to a task as a placeholder, <span class="task-ref" data-task="UUID">text</span>:
 * the text alone wherever Markdown shows, and the page that knows the task (a
 * meeting's minutes) puts its pill after it.
 */
final class TaskReferenceRenderer implements NodeRendererInterface
{
    public const string CLASS_NAME = 'task-ref';
    public const string ATTRIBUTE = 'data-task';

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        if (!$node instanceof TaskReference) {
            throw new \InvalidArgumentException(sprintf('Expected a %s, got %s.', TaskReference::class, $node::class));
        }

        return new HtmlElement('span', ['class' => self::CLASS_NAME, self::ATTRIBUTE => $node->taskId], htmlspecialchars($node->text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }
}
