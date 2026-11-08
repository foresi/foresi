<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Markdown;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * The only class an element of rendered Markdown may carry is the mention chip's.
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
        return 'mention' === $value ? $value : null;
    }
}
