<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Pdf;

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
    private const string FONT = 'intertnum';
    private const string DISPLAY_FONT = 'interdisplay';
    private const float FONT_SIZE = 9.0;
    /** How far above the bottom of the content the footer line starts, in millimetres. */
    private const float FOOTER_ABOVE_CONTENT_BOTTOM_MM = 8.0;
    /** How much lower than the content the page's own bottom margin lies, in millimetres. */
    private const float PAGE_BOTTOM_BELOW_CONTENT_MM = 5.0;
    private const float FOOTER_SIZE = 7.0;
    private const string CREATOR = 'Foresi';

    private string $fontDirectory;

    /**
     * @param list<string> $logoDirectories
     */
    public function __construct(
        string $fontDirectory = __DIR__.'/../../resources/pdf-fonts',
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
     * @param PageLayout|null                   $layout   the paper and margins; an invoice's when not given
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
        ?PageLayout $layout = null,
    ): string {
        $layout ??= PageLayout::invoice();
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

        $regular = $pdf->font->insert($pdf->pon, self::FONT, '', self::FONT_SIZE, ifile: $this->fontDirectory.'/intertnum.json');
        $pdf->font->insert($pdf->pon, self::FONT, 'B', self::FONT_SIZE, ifile: $this->fontDirectory.'/intertnumb.json');
        $pdf->font->insert($pdf->pon, self::DISPLAY_FONT, '', self::FONT_SIZE, ifile: $this->fontDirectory.'/interdisplay.json');
        // Back to the regular face as the current font.
        $pdf->font->insert($pdf->pon, self::FONT, '', self::FONT_SIZE, ifile: $this->fontDirectory.'/intertnum.json');

        // Every page keeps these: a table that breaks continues inside them.
        $top = $layout->marginTopMm;
        $pageBottom = max(0.0, $layout->marginBottomMm - self::PAGE_BOTTOM_BELOW_CONTENT_MM);
        $pdf->addPage([
            'width' => $layout->widthMm,
            'height' => $layout->heightMm,
            'orientation' => $layout->landscape ? 'L' : 'P',
            'margin' => ['PL' => $layout->marginLeftMm, 'PR' => $layout->marginRightMm, 'PT' => $top, 'PB' => $pageBottom, 'HB' => $top, 'FT' => $pageBottom, 'CT' => $top, 'CB' => $layout->marginBottomMm],
        ]);
        $pdf->page->addContent($regular['out']);

        $page = $pdf->page->getPageID();
        $bodyTop = $top;
        if ('' !== $htmlHead) {
            $pdf->addHTMLCell(html: $htmlHead, posx: $layout->marginLeftMm, posy: $top, width: $layout->contentWidthMm());
            $bodyTop = $pdf->getLastHTMLCellEnd()['y'];
        }

        $pdf->addHTMLCell(html: $htmlBody, posx: $layout->marginLeftMm, posy: $bodyTop, width: $layout->contentWidthMm());

        if ($qr instanceof QrFigure) {
            $this->drawQr($pdf, $qr, $page, $bodyTop - $top, $layout);
        }

        if ($footer instanceof \Closure) {
            $this->drawFooters($pdf, $footer, $layout);
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
    private function drawFooters(Tcpdf $pdf, \Closure $footer, PageLayout $layout): void
    {
        $font = $pdf->font->insert($pdf->pon, self::FONT, '', self::FOOTER_SIZE, ifile: $this->fontDirectory.'/intertnum.json');
        $pages = $pdf->page->getPageID() + 1;
        for ($page = 0; $page < $pages; ++$page) {
            $text = $pdf->getTextCell(
                $footer($page + 1, $pages),
                $layout->marginLeftMm,
                $layout->pageHeightMm() - $layout->marginBottomMm + self::FOOTER_ABOVE_CONTENT_BOTTOM_MM,
                $layout->contentWidthMm(),
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
    private function drawQr(Tcpdf $pdf, QrFigure $qr, int $pid, float $shift, PageLayout $layout): void
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
        $pageHeight = $pdf->toPoints($layout->pageHeightMm());
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
