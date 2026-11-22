<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Pdf;

use Foresi\Documents\Application\PdfRenderer;
use Foresi\Documents\Domain\PageSettings;
use Foresi\Shared\Infrastructure\Pdf\PageLayout;
use Foresi\Shared\Infrastructure\Pdf\PdfEngine;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Renders a filled template with the shared PDF engine: PDF/A-3a, the bundled
 * Inter fonts, local files only (ADR 0042). Every page gets the footer
 * "page X of Y" in the document's language.
 */
final readonly class TcpdfDocumentRenderer implements PdfRenderer
{
    public function __construct(private PdfEngine $engine, private TranslatorInterface $translator)
    {
    }

    public function render(string $html, PageSettings $page, string $title, string $language): string
    {
        $layout = new PageLayout($page->paper->widthMm(), $page->paper->heightMm(), $page->landscape, $page->marginTopMm, $page->marginRightMm, $page->marginBottomMm, $page->marginLeftMm);

        return $this->engine->document(
            $title,
            $language,
            $html,
            null,
            footer: fn (int $number, int $pages): string => $this->translator->trans('documents.pdf.footer', ['page' => $number, 'pages' => $pages], 'messages', $language),
            layout: $layout,
        );
    }
}
