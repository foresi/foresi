<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Markdown;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/**
 * A mention as a chip of plain text; no link, as the person's page may be closed to the reader.
 */
final class MentionRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        if (!$node instanceof Mention) {
            throw new \InvalidArgumentException(sprintf('Expected a %s, got %s.', Mention::class, $node::class));
        }

        return new HtmlElement('span', ['class' => 'mention'], htmlspecialchars('@'.$node->displayName, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }
}
