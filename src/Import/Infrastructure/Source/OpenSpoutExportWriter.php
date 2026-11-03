<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Source;

use App\Import\Application\ExportWriter;
use App\Import\Application\Source\SourceFormat;
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
 * CSV as UTF-8 with a byte order mark and semicolons, or XLSX. Every cell is
 * text. In CSV a cell that a spreadsheet would run as a formula gets a leading
 * apostrophe, as in the error file; the import strips it again. XLSX cells are
 * typed strings, so they are written as they are.
 */
final readonly class OpenSpoutExportWriter implements ExportWriter
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function write(array $fields, iterable $rows, SourceFormat $format, string $path): void
    {
        $style = OpenSpoutErrorFileWriter::textStyle();
        $writer = $this->writer($format, $style);
        $writer->openToFile($path);
        try {
            $writer->addRow($this->row($format, array_map(fn (\App\Import\Domain\FieldDefinition $field): string => $this->translator->trans($field->labelKey), $fields), $style));
            foreach ($rows as $values) {
                $writer->addRow($this->row($format, array_map(static fn (\App\Import\Domain\FieldDefinition $field): string => $values[$field->key] ?? '', $fields), $style));
            }
        } finally {
            $writer->close();
        }
    }

    private function writer(SourceFormat $format, Style $style): WriterInterface
    {
        if (SourceFormat::Xlsx === $format) {
            return new XlsxWriter(new XlsxOptions(FALLBACK_STYLE: $style));
        }

        return new CsvWriter(new CsvOptions(FIELD_DELIMITER: ';', SHOULD_ADD_BOM: true));
    }

    /**
     * @param list<string> $cells
     */
    private function row(SourceFormat $format, array $cells, Style $style): Row
    {
        return new Row(array_map(static fn (string $cell): StringCell => new StringCell(SourceFormat::Csv === $format ? OpenSpoutErrorFileWriter::neutralise($cell) : $cell, $style), $cells));
    }
}
