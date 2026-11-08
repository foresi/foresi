<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Twig;

use App\Shared\Application\Markdown;
use Twig\Attribute\AsTwigFilter;

final readonly class MarkdownFilter
{
    public function __construct(private Markdown $markdown)
    {
    }

    /**
     * Text people write, rendered from Markdown to sanitized HTML. Print it inside
     * an element with the "markdown" class, which styles what it may contain.
     */
    #[AsTwigFilter('markdown', isSafe: ['html'])]
    public function render(?string $source): string
    {
        return $this->markdown->toHtml($source ?? '');
    }
}
