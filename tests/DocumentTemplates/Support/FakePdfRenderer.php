<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\DocumentTemplates\Support;

use Foresi\DocumentTemplates\Application\PdfRenderer;
use Foresi\DocumentTemplates\Domain\PageSettings;

/**
 * A tiny PDF that carries the HTML it was given, so a test can read what was rendered.
 */
final class FakePdfRenderer implements PdfRenderer
{
    public ?string $lastHtml = null;

    public function render(string $html, PageSettings $page, string $title, string $language): string
    {
        $this->lastHtml = $html;

        return "%PDF-1.7\n% ".$title."\n".$html."\n%%EOF\n";
    }
}
