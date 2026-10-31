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
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CSV as UTF-8 with a byte order mark and semicolons, or XLSX. Every cell is
 * text and formulas are neutralised the way the error file does it.
 */
final readonly class OpenSpoutExportWriter implements ExportWriter
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function write(array $fields, iterable $rows, SourceFormat $format, string $path): void
    {
        $writer = $this->writer($format);
        $writer->openToFile($path);
        try {
            $writer->addRow($this->row(array_map(fn (\App\Import\Domain\FieldDefinition $field): string => $this->translator->trans($field->labelKey), $fields)));
            foreach ($rows as $values) {
                $writer->addRow($this->row(array_map(static fn (\App\Import\Domain\FieldDefinition $field): string => $values[$field->key] ?? '', $fields)));
            }
        } finally {
            $writer->close();
        }
    }

    private function writer(SourceFormat $format): WriterInterface
    {
        if (SourceFormat::Xlsx === $format) {
            return new XlsxWriter();
        }
        $options = new CsvOptions();
        $options->FIELD_DELIMITER = ';';
        $options->SHOULD_ADD_BOM = true;

        return new CsvWriter($options);
    }

    /**
     * @param list<string> $cells
     */
    private function row(array $cells): Row
    {
        return new Row(array_map(static fn (string $cell): StringCell => new StringCell(OpenSpoutErrorFileWriter::neutralise($cell), null), $cells));
    }
}
