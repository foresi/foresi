<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Twig;

use Twig\Attribute\AsTwigFilter;

final readonly class TextFilters
{
    /**
     * Turns the HTML entities of already escaped output back into text, so a
     * value taken from rendered markup can be escaped exactly once again.
     * Apply it after "striptags" (decoding first would reintroduce markup).
     */
    #[AsTwigFilter('html_decode')]
    public function decode(string $escaped): string
    {
        return html_entity_decode($escaped, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }
}
