<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Source;

use App\Import\Application\ErrorFileWriter;
use App\Import\Application\Source\SourceFormat;
use App\Import\Application\Source\SourceSettings;
use App\Import\Domain\Import;
use App\Import\Domain\RowError;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Writes the error file in the format of the upload: XLSX as XLSX, CSV as
 * UTF-8 CSV with a byte order mark and the original delimiter. Every cell is
 * text; one that a spreadsheet would run as a formula (starting with = + - @,
 * a tab or a carriage return) gets a leading apostrophe.
 */
final readonly class OpenSpoutErrorFileWriter implements ErrorFileWriter
{
    private const string FORMULA_START = "=+-@\t\r";

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function write(Import $import, iterable $rows, string $locale, string $path): void
    {
        $settings = SourceSettings::fromArray($import->sourceSettings());
        $headers = $import->headers();
        $columnOf = [];
        foreach ($import->mapping()->columns as $column => $field) {
            if (null !== $field && isset($headers[$column])) {
                $columnOf[$field] = $headers[$column];
            }
        }

        $style = self::textStyle();
        $writer = $this->writer($settings, $style);
        $writer->openToFile($path);
        try {
            $writer->addRow($this->row([...$headers, $this->translator->trans('import.error_file.column', [], 'messages', $locale)], $style));
            foreach ($rows as $row) {
                $cells = array_pad($row->raw(), \count($headers), '');
                $messages = array_map(fn (RowError $error): string => $this->message($error, $columnOf, $locale), $row->errors());
                $writer->addRow($this->row([...$cells, implode(' ', $messages)], $style));
            }
        } finally {
            $writer->close();
        }
    }

    /** Text that a spreadsheet would read as a formula, made plain text. */
    public static function neutralise(string $cell): string
    {
        return '' !== $cell && str_contains(self::FORMULA_START, $cell[0]) ? "'".$cell : $cell;
    }

    /**
     * The one style of every cell of a file: the XLSX default font with
     * wrapped text. OpenSpout registers styles per object, so sharing one
     * instance keeps a file with many multi-line cells at a single style.
     */
    public static function textStyle(): Style
    {
        return new Style(fontSize: XlsxOptions::DEFAULT_FONT_SIZE, fontName: XlsxOptions::DEFAULT_FONT_NAME, shouldWrapText: true);
    }

    private function writer(SourceSettings $settings, Style $style): WriterInterface
    {
        if (SourceFormat::Xlsx === $settings->format) {
            return new XlsxWriter(new XlsxOptions(FALLBACK_STYLE: $style));
        }

        $delimiter = $settings->delimiter ?? '';

        return new CsvWriter(new CsvOptions(FIELD_DELIMITER: '' === $delimiter ? ';' : $delimiter, SHOULD_ADD_BOM: true));
    }

    /**
     * @param list<string> $cells
     */
    private function row(array $cells, Style $style): Row
    {
        return new Row(array_map(static fn (string $cell): StringCell => new StringCell(self::neutralise($cell), $style), $cells));
    }

    /**
     * @param array<string, string> $columnOf header by field key
     */
    private function message(RowError $error, array $columnOf, string $locale): string
    {
        $message = $this->translator->trans($error->messageKey, $error->parameters, 'messages', $locale);
        $column = $columnOf[$error->field] ?? null;

        return null === $column ? $message : $this->translator->trans('import.error_file.in_column', ['message' => $message, 'column' => $column], 'messages', $locale);
    }
}
