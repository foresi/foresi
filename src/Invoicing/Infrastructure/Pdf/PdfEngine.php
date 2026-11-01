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
    private const float MARGIN_MM = 15.0;
    private const float PAGE_WIDTH_MM = 210.0;
    private const float PAGE_HEIGHT_MM = 297.0;
    private const float CONTENT_BOTTOM_MM = 20.0;
    private const float FOOTER_FROM_BOTTOM_MM = 12.0;
    private const float FOOTER_SIZE = 7.0;
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
     * @param string                            $language BCP 47 tag of the document, for example "cs"
     * @param string                            $htmlHead what comes before the body: the QR code keeps its place below it, however tall it grows
     * @param (\Closure(int, int): string)|null $footer   the footer of a page, from its number and the number of pages
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
        ?\Closure $footer = null,
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
        // Back to the regular face as the current font.
        $pdf->font->insert($pdf->pon, self::FONT, '', self::FONT_SIZE, ifile: $this->fontDirectory.'/inter.json');

        // Every page keeps these: a table that breaks continues inside them.
        $margin = self::MARGIN_MM;
        $pdf->addPage(['margin' => ['PL' => $margin, 'PR' => $margin, 'PT' => $margin, 'PB' => $margin, 'HB' => $margin, 'FT' => $margin, 'CT' => $margin, 'CB' => self::CONTENT_BOTTOM_MM]]);
        $pdf->page->addContent($regular['out']);

        $page = $pdf->page->getPageID();
        $bodyTop = self::MARGIN_MM;
        if ('' !== $htmlHead) {
            $pdf->addHTMLCell(html: $htmlHead, posx: self::MARGIN_MM, posy: self::MARGIN_MM, width: self::PAGE_WIDTH_MM - 2 * self::MARGIN_MM);
            $bodyTop = $pdf->getLastHTMLCellEnd()['y'];
        }

        $pdf->addHTMLCell(html: $htmlBody, posx: self::MARGIN_MM, posy: $bodyTop, width: self::PAGE_WIDTH_MM - 2 * self::MARGIN_MM);

        if ($qr instanceof QrFigure) {
            $this->drawQr($pdf, $qr, $page, $bodyTop - self::MARGIN_MM);
        }

        if ($footer instanceof \Closure) {
            $this->drawFooters($pdf, $footer);
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
     * Written last, when the number of pages is known, as artifacts: a footer is
     * pagination, not content of the document.
     *
     * @param \Closure(int, int): string $footer
     */
    private function drawFooters(Tcpdf $pdf, \Closure $footer): void
    {
        $font = $pdf->font->insert($pdf->pon, self::FONT, '', self::FOOTER_SIZE, ifile: $this->fontDirectory.'/inter.json');
        $pages = $pdf->page->getPageID() + 1;
        for ($page = 0; $page < $pages; ++$page) {
            $text = $pdf->getTextCell(
                $footer($page + 1, $pages),
                self::MARGIN_MM,
                self::PAGE_HEIGHT_MM - self::FOOTER_FROM_BOTTOM_MM,
                self::PAGE_WIDTH_MM - 2 * self::MARGIN_MM,
                valign: 'T',
                halign: 'L',
            );
            $pdf->addArtifactContent($font['out'].$text, $page, 'Pagination', 'Footer');
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
     * @param float $shift how far a head pushed the body down, in millimetres
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
