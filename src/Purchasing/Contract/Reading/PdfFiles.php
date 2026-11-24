<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Contract\Reading;

/**
 * What the readers take out of a PDF: the files attached to it and the images
 * placed on its first pages. It is a port, so that a reader of any module
 * (a Czech format in Country, Factur-X in Purchasing) needs no PDF library of
 * its own, and every reader meets the same limits.
 */
interface PdfFiles
{
    /**
     * The files attached to the PDF (an invoice's XML, for example).
     *
     * @return list<DocumentFile>
     *
     * @throws ReaderFailed when the PDF is encrypted, corrupt or over a limit
     */
    public function embedded(DocumentFile $pdf): array;

    /**
     * The images placed on the first $maxPages pages, as PNG bytes. These are
     * the images stored in the file; a page drawn with vectors (a QR code made
     * of lines) has none, because a page is never rendered.
     *
     * @return list<string> PNG bytes
     *
     * @throws ReaderFailed when the PDF is encrypted, corrupt or over a limit
     */
    public function pageImages(DocumentFile $pdf, int $maxPages): array;
}
