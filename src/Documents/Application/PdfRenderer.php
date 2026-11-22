<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Application;

use Foresi\Documents\Domain\PageSettings;

/**
 * Turns the HTML of a filled template into a PDF/A document with a footer
 * "page X of Y" (ADR 0042). Documents depends on this and nothing else of the
 * PDF engine.
 */
interface PdfRenderer
{
    /**
     * @param string $language BCP 47 tag of the document, such as "cs"
     *
     * @return string the PDF bytes
     */
    public function render(string $html, PageSettings $page, string $title, string $language): string;
}
