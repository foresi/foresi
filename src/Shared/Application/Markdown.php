<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Renders text people write (task descriptions, comments) from Markdown to HTML
 * that is safe to print as is: raw HTML in the source is escaped, and the output
 * holds only an allow-list of elements, links to http, https and mailto, and
 * images from the installation's own attachment downloads.
 */
interface Markdown
{
    public function toHtml(string $source): string;
}
