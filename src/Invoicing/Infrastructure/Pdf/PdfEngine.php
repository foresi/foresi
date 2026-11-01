<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Pdf;

use Com\Tecnick\Pdf\AFRelationship;
use Com\Tecnick\Pdf\PdfConformance;
use Com\Tecnick\Pdf\Tcpdf;

/**
 * Renders HTML into a tagged PDF/A-3a document, optionally carrying one
 * machine-readable file. The only class that talks to tc-lib-pdf.
 *
 * The engine reads local files from two directories only, the bundled fonts and
 * the logos, and never from the network. The logo directories are resolved on each
 * document, because the first image of an installation creates them.
 */
final readonly class PdfEngine
{
    private const string FONT = 'inter';
    private const float FONT_SIZE = 9.0;
    private const float PAGE_NUMBER_SIZE = 7.0;
    private const float PAGE_NUMBER_HEIGHT_MM = 3.5;
    private const float PAGE_WIDTH_MM = 210.0;
    private const float PAGE_HEIGHT_MM = 297.0;
    private const string CREATOR = 'Foresi';

    private string $fontDirectory;

    /**
     * @param list<string> $logoDirectories
     */
    public function __construct(
        string $fontDirectory = __DIR__.'/../../../../resources/pdf-fonts',
        private array $logoDirectories = [],
    ) {
        $font = realpath($fontDirectory);
        if (false === $font) {
            throw new \InvalidArgumentException('The PDF font directory does not exist.');
        }
        $this->fontDirectory = $font;
    }

    /**
     * @param string                    $language   BCP 47 tag of the document, for example "cs"
     * @param string                    $htmlHead   what comes before the body: the QR code keeps its place below it, however tall it grows
     * @param string                    $htmlFoot   what comes after the body, whole on one page
     * @param \Closure(int, int):string $pageNumber the text of a page number, from the page and the count of pages
     *
     * @return string the PDF bytes
     */
    public function document(
        string $title,
        string $language,
        string $htmlBody,
        ?EmbeddedFile $file,
        ?QrFigure $qr = null,
        string $htmlHead = '',
        string $htmlFoot = '',
        PdfLayout $layout = new PdfLayout(),
        ?\Closure $pageNumber = null,
    ): string {
        $logos = $this->existingLogoDirectories();
        $pdf = new Tcpdf(
            unit: 'mm',
            isunicode: true,
            subsetfont: true,
            compress: true,
            mode: PdfConformance::Pdfa3a,
            fileOptions: [
                'allowedHosts' => [],
                'allowedPaths' => [$this->fontDirectory, $this->colourProfileDirectory(), ...$logos],
                'markupAllowedPaths' => $logos,
            ],
        );

        $pdf->setCreator(self::CREATOR);
        $pdf->setProducerSuffix(self::CREATOR);
        $pdf->setTitle($title);
        $pdf->setLanguage($language);
        $pdf->setViewerPreferences(['DisplayDocTitle' => true]);

        $regular = $pdf->font->insert($pdf->pon, self::FONT, '', self::FONT_SIZE, ifile: $this->fontDirectory.'/inter.json');
        $pdf->font->insert($pdf->pon, self::FONT, 'B', self::FONT_SIZE, ifile: $this->fontDirectory.'/interb.json');
        foreach ($layout->faces as $face) {
            $pdf->font->insert($pdf->pon, $face->family, $face->style, self::FONT_SIZE, ifile: $this->fontDirectory.'/'.$face->file);
        }
        // Back to the regular face as the current font.
        $pdf->font->insert($pdf->pon, self::FONT, '', self::FONT_SIZE, ifile: $this->fontDirectory.'/inter.json');

        // Every page keeps these: a table that breaks continues inside them.
        $margin = $layout->marginMm;
        $width = self::PAGE_WIDTH_MM - 2 * $margin;
        $page = ['margin' => ['PL' => $margin, 'PR' => $margin, 'PT' => $margin, 'PB' => $margin, 'HB' => $margin, 'FT' => $margin, 'CT' => $margin, 'CB' => $margin]];
        $pdf->addPage($page);
        $pdf->page->addContent($regular['out']);

        $first = $pdf->page->getPageID();
        $bodyTop = $margin;
        if ('' !== $htmlHead) {
            $pdf->addHTMLCell(html: $htmlHead, posx: $margin, posy: $margin, width: $width);
            $bodyTop = $pdf->getLastHTMLCellEnd()['y'];
        }

        $pdf->addHTMLCell(html: $htmlBody, posx: $margin, posy: $bodyTop, width: $width);

        $footPage = $first;
        $footTop = 0.0;
        if ('' !== $htmlFoot) {
            $footTop = $this->footTop($pdf, $layout, $page, $regular['out']);
            $footPage = $pdf->page->getPageID();
            $pdf->addHTMLCell(html: $htmlFoot, posx: $margin, posy: $footTop, width: $width);
        }

        if ($qr instanceof QrFigure) {
            match ($qr->anchor) {
                QrAnchor::Body => $this->drawQr($pdf, $qr, $first, $bodyTop - $margin),
                QrAnchor::Foot => $this->drawQr($pdf, $qr, $footPage, $footTop),
            };
        }

        if (null !== $layout->pageFace && $pageNumber instanceof \Closure) {
            $this->numberPages($pdf, $layout->pageFace, $margin, $first, $pdf->page->getPageID(), $pageNumber);
        }

        if ($file instanceof EmbeddedFile) {
            $pdf->addContentAsEmbeddedFile(
                $file->name,
                $file->content,
                $file->mime,
                AFRelationship::Alternative,
            );
        }

        return $pdf->getOutPDFString();
    }

    /**
     * Where the block after the body starts; a block that does not fit opens a page.
     *
     * @param array<string, mixed> $page the definition of a page
     */
    private function footTop(Tcpdf $pdf, PdfLayout $layout, array $page, string $font): float
    {
        $end = $pdf->getLastHTMLCellEnd()['y'];
        $bottom = self::PAGE_HEIGHT_MM - $layout->marginMm;
        $standing = $bottom - $layout->footHeightMm;
        $top = $layout->footAtBottom ? $standing : $end + $layout->footGapMm;
        if ($top >= $end && $top <= $standing) {
            return $top;
        }

        $pdf->addPage($page);
        $pdf->page->addContent($font);

        return $layout->footAtBottom ? $standing : $layout->marginMm;
    }

    /**
     * A page number is page furniture, so it stays out of the structure of the document.
     *
     * @param \Closure(int, int):string $text
     */
    private function numberPages(Tcpdf $pdf, string $face, float $margin, int $first, int $last, \Closure $text): void
    {
        $count = $last - $first + 1;
        $font = $pdf->font->insert($pdf->pon, $face, '', self::PAGE_NUMBER_SIZE);
        for ($pid = $first; $pid <= $last; ++$pid) {
            $pdf->addArtifactContent(
                $font['out'].$pdf->getTextCell(
                    $text($pid - $first + 1, $count),
                    $margin,
                    self::PAGE_HEIGHT_MM - $margin + 2.0,
                    self::PAGE_WIDTH_MM - 2 * $margin,
                    self::PAGE_NUMBER_HEIGHT_MM,
                    halign: 'R',
                ),
                $pid,
                'Pagination',
                'Footer',
            );
        }
    }

    /**
     * @return list<string>
     */
    private function existingLogoDirectories(): array
    {
        return array_values(array_filter(array_map(realpath(...), $this->logoDirectories), \is_string(...)));
    }

    /**
     * The library reads its bundled sRGB profile, which PDF/A requires, from here.
     */
    private function colourProfileDirectory(): string
    {
        $file = new \ReflectionClass(Tcpdf::class)->getFileName();
        \assert(false !== $file);

        return dirname($file).'/include';
    }

    /**
     * @param float $shift how far the place the figure is measured from lies down the page, in millimetres
     */
    private function drawQr(Tcpdf $pdf, QrFigure $qr, int $pid, float $shift): void
    {
        $top = $qr->y + $shift;

        // Error correction level M; the size is a whole number of millimetres.
        $drawing = $pdf->getBarcode(
            'QRCODE,M',
            $qr->payload,
            $qr->x,
            $top,
            (int) round($qr->sizeMm),
            (int) round($qr->sizeMm),
        );

        // The Figure bounding box is in points from the bottom left corner of the page.
        $pageHeight = $pdf->toPoints(self::PAGE_HEIGHT_MM);
        $pdf->addTaggedFigureContent(
            $drawing,
            $pid,
            $qr->alt,
            [
                $pdf->toPoints($qr->x),
                $pageHeight - $pdf->toPoints($top + $qr->sizeMm),
                $pdf->toPoints($qr->x + $qr->sizeMm),
                $pageHeight - $pdf->toPoints($top),
            ],
        );
    }
}
