<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Markdown;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * An image shows only from the installation's own attachment downloads: an external
 * image would tell its host who reads the text, and when.
 */
final class OwnAttachmentImages implements AttributeSanitizerInterface
{
    public function getSupportedElements(): array
    {
        return ['img'];
    }

    public function getSupportedAttributes(): array
    {
        return ['src'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return 1 === preg_match('#\A/attachments/[A-Za-z0-9][A-Za-z0-9._~%/-]*\z#', $value) && !str_contains($value, '..') ? $value : null;
    }
}
