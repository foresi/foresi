<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Markdown;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * The task a reference's placeholder names: a UUID, nothing else.
 */
final class TaskReferenceId implements AttributeSanitizerInterface
{
    public function getSupportedElements(): array
    {
        return ['span'];
    }

    public function getSupportedAttributes(): array
    {
        return [TaskReferenceRenderer::ATTRIBUTE];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return 1 === preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $value) ? $value : null;
    }
}
