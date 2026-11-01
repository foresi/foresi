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
 * the logos, and never from the network.
 */
final readonly class PdfEngine
{
    private const string FONT = 'inter';
    private const float FONT_SIZE = 9.0;
    private const float MARGIN_MM = 15.0;
    private const float PAGE_WIDTH_MM = 210.0;
    private const float PAGE_HEIGHT_MM = 297.0;
    private const string CREATOR = 'Foresi';

    private string $fontDirectory;

    /** @var list<string> */
    private array $logoDirectories;

    /**
     * @param list<string> $logoDirectories
     */
    public function __construct(
        string $fontDirectory = __DIR__.'/../../../../resources/pdf-fonts',
        array $logoDirectories = [],
    ) {
        $font = realpath($fontDirectory);
        if (false === $font) {
            throw new \InvalidArgumentException('The PDF font directory does not exist.');
        }
        $this->fontDirectory = $font;

        $logos = [];
        foreach ($logoDirectories as $directory) {
            $path = realpath($directory);
            if (false !== $path) {
                $logos[] = $path;
            }
        }
        $this->logoDirectories = $logos;
    }

    /**
     * @param string $language BCP 47 tag of the document, for example "cs"
     *
     * @return string the PDF bytes
     */
    public function document(
        string $title,
        string $language,
        string $htmlBody,
        ?EmbeddedFile $file,
        ?QrFigure $qr = null,
    ): string {
        $pdf = new Tcpdf(
            unit: 'mm',
            isunicode: true,
            subsetfont: true,
            compress: true,
            mode: PdfConformance::Pdfa3a,
            fileOptions: [
                'allowedHosts' => [],
                'allowedPaths' => [$this->fontDirectory, $this->colourProfileDirectory(), ...$this->logoDirectories],
                'markupAllowedPaths' => $this->logoDirectories,
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

        $pdf->addPage();
        $pdf->page->addContent($regular['out']);

        $pdf->addHTMLCell(
            html: $htmlBody,
            posx: self::MARGIN_MM,
            posy: self::MARGIN_MM,
            width: self::PAGE_WIDTH_MM - 2 * self::MARGIN_MM,
        );

        if ($qr instanceof QrFigure) {
            $this->drawQr($pdf, $qr, $pdf->page->getPageID());
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
     * The library reads its bundled sRGB profile, which PDF/A requires, from here.
     */
    private function colourProfileDirectory(): string
    {
        $file = new \ReflectionClass(Tcpdf::class)->getFileName();
        \assert(false !== $file);

        return dirname($file).'/include';
    }

    private function drawQr(Tcpdf $pdf, QrFigure $qr, int $pid): void
    {
        // Error correction level M; the size is a whole number of millimetres.
        $drawing = $pdf->getBarcode(
            'QRCODE,M',
            $qr->payload,
            $qr->x,
            $qr->y,
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
                $pageHeight - $pdf->toPoints($qr->y + $qr->sizeMm),
                $pdf->toPoints($qr->x + $qr->sizeMm),
                $pageHeight - $pdf->toPoints($qr->y),
            ],
        );
    }
}
