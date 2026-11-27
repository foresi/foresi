<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Markdown;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * The only classes an element of rendered Markdown may carry: the mention chip's
 * and the placeholder of a reference to a task.
 */
final class MentionClass implements AttributeSanitizerInterface
{
    public function getSupportedElements(): array
    {
        return ['span'];
    }

    public function getSupportedAttributes(): array
    {
        return ['class'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return \in_array($value, ['mention', TaskReferenceRenderer::CLASS_NAME], true) ? $value : null;
    }
}
