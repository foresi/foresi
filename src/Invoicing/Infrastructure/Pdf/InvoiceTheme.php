<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Pdf;

/**
 * The look of an invoice: which template draws it and where things sit on the page.
 * A theme holds layout only. What a document says comes from TcpdfInvoicePdf, the same
 * for each theme, so a theme can move a statutory field but never lose or change it.
 */
final readonly class InvoiceTheme
{
    public const string CLASSIC = 'classic';
    public const string STATEMENT = 'statement';
    public const string PANEL = 'panel';
    public const string SLIP = 'slip';

    /** Inter with tabular figures, and Inter Display for the large type; see resources/pdf-fonts/README.md. */
    private const array DESIGN_FACES = [
        ['intertnum', '', 'intertnum.json'],
        ['intertnum', 'B', 'intertnumb.json'],
        ['interdisplay', '', 'interdisplay.json'],
    ];

    /**
     * @param array{float, float} $qr            where the code sits, in millimetres
     * @param array{float, float} $logoBox       the most a logo may take, in millimetres
     * @param array{float, float} $stampBox      the most a stamp may take, in millimetres
     * @param array<string, int>  $payerColumns  shares of the page; the text column takes what the others leave
     * @param array<string, int>  $plainColumns  the same for a document without VAT
     * @param float|null          $tableFontPt   the type of the items table, which then sizes its columns to the document; see InvoiceColumns
     * @param float               $cellPaddingMm the left and right padding of a table cell together
     */
    private function __construct(
        public string $template,
        public array $qr,
        public QrAnchor $qrAnchor,
        public array $logoBox,
        public array $stampBox,
        public array $payerColumns,
        public array $plainColumns,
        public PdfLayout $layout,
        public ?float $tableFontPt = null,
        public float $cellPaddingMm = 0.0,
    ) {
    }

    public function tableWidthMm(): float
    {
        return 210.0 - 2 * $this->layout->marginMm;
    }

    public static function named(string $name): self
    {
        return match ($name) {
            self::CLASSIC => self::classic(),
            self::STATEMENT => self::statement(),
            self::PANEL => self::panel(),
            self::SLIP => self::slip(),
            default => throw new \InvalidArgumentException('There is no invoice theme "'.$name.'".'),
        };
    }

    private static function classic(): self
    {
        return new self(
            'invoicing/pdf/invoice.html.twig',
            [162.0, 21.0],
            QrAnchor::Body,
            [60.0, 20.0],
            [55.0, 28.0],
            ['quantity' => 7, 'unit' => 5, 'price' => 11, 'discount' => 6, 'rate' => 6, 'base' => 11, 'vat' => 10, 'total' => 11],
            ['quantity' => 9, 'unit' => 6, 'price' => 14, 'discount' => 7, 'total' => 14],
            new PdfLayout(),
        );
    }

    private static function statement(): self
    {
        return new self(
            'invoicing/pdf/theme/statement.html.twig',
            [160.0, 9.5],
            QrAnchor::Foot,
            [52.0, 16.0],
            [48.0, 24.0],
            ['quantity' => 7, 'unit' => 5, 'price' => 11, 'discount' => 6, 'rate' => 6, 'base' => 11, 'vat' => 10, 'total' => 12],
            ['quantity' => 9, 'unit' => 6, 'price' => 14, 'discount' => 7, 'total' => 14],
            new PdfLayout(marginMm: 18.0, faces: self::faces(), footHeightMm: 44.0, footGapMm: 8.0, pageFace: 'intertnum'),
            8.0,
            2.9,
        );
    }

    private static function panel(): self
    {
        return new self(
            'invoicing/pdf/theme/panel.html.twig',
            [125.6, 78.0],
            QrAnchor::Body,
            [52.0, 16.0],
            [48.0, 24.0],
            ['quantity' => 7, 'unit' => 5, 'price' => 10, 'discount' => 6, 'rate' => 6, 'base' => 11, 'vat' => 10, 'total' => 12],
            ['quantity' => 9, 'unit' => 6, 'price' => 14, 'discount' => 7, 'total' => 14],
            new PdfLayout(marginMm: 15.0, faces: self::faces(), pageFace: 'intertnum'),
            8.0,
            2.9,
        );
    }

    private static function slip(): self
    {
        return new self(
            'invoicing/pdf/theme/slip.html.twig',
            [15.0, 11.5],
            QrAnchor::Foot,
            [52.0, 14.0],
            [48.0, 24.0],
            ['quantity' => 7, 'unit' => 5, 'price' => 10, 'discount' => 6, 'rate' => 6, 'base' => 11, 'vat' => 10, 'total' => 12],
            ['quantity' => 9, 'unit' => 6, 'price' => 14, 'discount' => 7, 'total' => 14],
            new PdfLayout(marginMm: 15.0, faces: self::faces(), footHeightMm: 46.0, footAtBottom: true, footGapMm: 6.0, pageFace: 'intertnum'),
            8.0,
            2.9,
        );
    }

    /**
     * @return list<PdfFace>
     */
    private static function faces(): array
    {
        return array_map(static fn (array $face): PdfFace => new PdfFace(...$face), self::DESIGN_FACES);
    }
}
