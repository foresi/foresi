<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Infrastructure\Source;

use PHPUnit\Framework\Assert;

/**
 * Reads the first sheet and the styles of a workbook the way a spreadsheet
 * does, independent of how the writer serialises the XML.
 */
final class XlsxSheet
{
    private const string MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private function __construct()
    {
    }

    /**
     * Every cell of the first sheet by reference, asserting that each one is
     * an inline string in the first cell style and none is a formula.
     *
     * @return array<string, string>
     */
    public static function textCells(string $path): array
    {
        $sheet = self::document($path, 'xl/worksheets/sheet1.xml');
        Assert::assertSame(0, $sheet->getElementsByTagNameNS(self::MAIN, 'f')->length, 'No cell is a formula.');

        $cells = [];
        foreach ($sheet->getElementsByTagNameNS(self::MAIN, 'c') as $cell) {
            $reference = $cell->getAttribute('r');
            Assert::assertSame('inlineStr', $cell->getAttribute('t'), $reference.' is text.');
            Assert::assertSame('0', $cell->getAttribute('s'), $reference.' has the one cell style.');
            $cells[$reference] = $cell->textContent;
        }

        return $cells;
    }

    /** The workbook has one cell style: the Calibri 12 default font with wrapped text. */
    public static function assertOneWrappedTextStyle(string $path): void
    {
        $styles = self::document($path, 'xl/styles.xml');
        $formats = $styles->getElementsByTagNameNS(self::MAIN, 'cellXfs')->item(0);
        Assert::assertNotNull($formats);
        $xfs = $formats->getElementsByTagNameNS(self::MAIN, 'xf');
        Assert::assertSame(1, $xfs->length, 'One cell style.');
        $xf = $xfs->item(0);
        Assert::assertInstanceOf(\DOMElement::class, $xf);
        $alignment = $xf->getElementsByTagNameNS(self::MAIN, 'alignment')->item(0);
        Assert::assertInstanceOf(\DOMElement::class, $alignment);
        Assert::assertSame('1', $alignment->getAttribute('wrapText'));

        $font = $styles->getElementsByTagNameNS(self::MAIN, 'font')->item((int) $xf->getAttribute('fontId'));
        Assert::assertInstanceOf(\DOMElement::class, $font);
        Assert::assertSame('Calibri', self::value($font, 'name'));
        Assert::assertSame('12', self::value($font, 'sz'));
    }

    private static function document(string $path, string $part): \DOMDocument
    {
        $zip = new \ZipArchive();
        Assert::assertTrue($zip->open($path));
        $xml = $zip->getFromName($part);
        $zip->close();
        Assert::assertIsString($xml);

        $document = new \DOMDocument();
        Assert::assertTrue($document->loadXML($xml));

        return $document;
    }

    private static function value(\DOMElement $parent, string $name): string
    {
        $element = $parent->getElementsByTagNameNS(self::MAIN, $name)->item(0);
        Assert::assertInstanceOf(\DOMElement::class, $element);

        return $element->getAttribute('val');
    }
}
