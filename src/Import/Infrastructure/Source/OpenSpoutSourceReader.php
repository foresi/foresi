<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Source;

use App\Import\Application\Source\Encoding;
use App\Import\Application\Source\SourceFormat;
use App\Import\Application\Source\SourceInspection;
use App\Import\Application\Source\SourceReader;
use App\Import\Application\Source\SourceRejected;
use App\Import\Application\Source\SourceSettings;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads CSV with plain PHP and XLSX with OpenSpout, both as streams.
 */
final readonly class OpenSpoutSourceReader implements SourceReader
{
    private const int SAMPLE_ROWS = 20;
    private const int HEADER_SEARCH_ROWS = 10;
    private const int DETECTION_BYTES = 65_536;
    private const string UTF8_BOM = "\xEF\xBB\xBF";

    public function __construct(
        private int $maxFileBytes = 50 * 1_048_576,
        private int $maxDataRows = 200_000,
        private int $maxUncompressedBytes = 500 * 1_048_576,
        private int $maxCompressionRatio = 100,
        private EncodingDetector $encodingDetector = new EncodingDetector(),
        private DelimiterDetector $delimiterDetector = new DelimiterDetector(),
        private HeaderRowDetector $headerRowDetector = new HeaderRowDetector(),
        private TextDecoder $textDecoder = new TextDecoder(),
    ) {
    }

    public function inspect(string $path, ?SourceSettings $override = null): SourceInspection
    {
        $format = $this->detectFormat($path);

        return match ($format) {
            SourceFormat::Csv => $this->inspectCsv($path, $override),
            SourceFormat::Xlsx => $this->inspectXlsx($path, $override),
        };
    }

    public function rows(string $path, SourceSettings $settings): iterable
    {
        if ($this->detectFormat($path) !== $settings->format) {
            throw SourceRejected::unknownFormat();
        }

        if (null === $settings->headerRow
            || (SourceFormat::Csv === $settings->format && (!$settings->encoding instanceof Encoding || null === $settings->delimiter))
        ) {
            $settings = $this->inspect($path, $settings)->settings();
        }

        $headerRow = $settings->headerRow ?? 1;

        return $this->dataRows($this->records($path, $settings), $headerRow);
    }

    /**
     * @param iterable<int, list<string>> $records
     *
     * @return \Generator<int, list<string>>
     */
    private function dataRows(iterable $records, int $headerRow): \Generator
    {
        $count = 0;
        foreach ($records as $number => $cells) {
            $cells = array_map($this->unquote(...), $cells);
            if ($number <= $headerRow) {
                continue;
            }
            if (++$count > $this->maxDataRows) {
                throw SourceRejected::tooManyRows($this->maxDataRows);
            }

            yield $number => $cells;
        }
    }

    private function inspectCsv(string $path, ?SourceSettings $override): SourceInspection
    {
        $head = (string) file_get_contents($path, false, null, 0, self::DETECTION_BYTES);
        $encoding = $override->encoding ?? $this->encodingDetector->detectFile($path);
        $delimiter = $override->delimiter ?? $this->delimiterDetector->detect(
            $this->textDecoder->decode(str_starts_with($head, self::UTF8_BOM) ? substr($head, 3) : $head, $encoding),
        );
        $settings = new SourceSettings(SourceFormat::Csv, $encoding, $delimiter, null, $override?->headerRow);

        return $this->summarise($this->records($path, $settings), $settings, [], null);
    }

    private function inspectXlsx(string $path, ?SourceSettings $override): SourceInspection
    {
        $sheets = $this->sheetNames($path);
        $sheet = $override->sheet ?? $sheets[0] ?? null;
        if (null === $sheet || !\in_array($sheet, $sheets, true)) {
            throw SourceRejected::unknownFormat();
        }
        $settings = new SourceSettings(SourceFormat::Xlsx, null, null, $sheet, $override?->headerRow);

        return $this->summarise($this->records($path, $settings), $settings, $sheets, $sheet);
    }

    /**
     * @param \Generator<int, list<string>> $records
     * @param list<string>                  $sheets
     */
    private function summarise(\Generator $records, SourceSettings $settings, array $sheets, ?string $sheet): SourceInspection
    {
        $buffer = [];
        while ($records->valid() && $records->key() <= self::HEADER_SEARCH_ROWS) {
            $buffer[$records->key()] = $records->current();
            $records->next();
        }
        if ([] === $buffer && !$records->valid()) {
            throw SourceRejected::empty();
        }

        // When the first rows are all blank, the header is the first row that has content.
        $headerRow = $settings->headerRow ?? ([] === $buffer ? $records->key() : $this->headerRowDetector->detect($buffer));
        $headers = null;
        $samples = [];
        $count = 0;

        foreach ($this->chain($buffer, $records) as $number => $cells) {
            if ($number < $headerRow) {
                continue;
            }
            if ($number === $headerRow) {
                $headers = $this->headers($cells);

                continue;
            }
            if (++$count > $this->maxDataRows) {
                throw SourceRejected::tooManyRows($this->maxDataRows);
            }
            // The mapping sees the same text as the check reads.
            if ($count <= self::SAMPLE_ROWS) {
                $samples[$number] = array_map($this->unquote(...), $cells);
            }
        }

        if (null === $headers) {
            throw SourceRejected::empty();
        }

        return new SourceInspection(
            $settings->format,
            $settings->encoding,
            $settings->delimiter,
            $sheets,
            $sheet,
            $headerRow,
            $headers,
            $samples,
            $count,
        );
    }

    /**
     * The records already taken off a generator followed by the rest of it.
     *
     * @param array<int, list<string>>      $taken
     * @param \Generator<int, list<string>> $rest
     *
     * @return \Generator<int, list<string>>
     */
    private function chain(array $taken, \Generator $rest): \Generator
    {
        yield from $taken;

        while ($rest->valid()) {
            yield $rest->key() => $rest->current();
            $rest->next();
        }
    }

    /**
     * @param list<string> $cells
     *
     * @return list<string>
     */
    private function headers(array $cells): array
    {
        $headers = [];
        foreach ($cells as $index => $cell) {
            $name = trim(str_replace("\u{FEFF}", '', $cell));
            $headers[] = '' === $name ? 'Column '.($index + 1) : $name;
        }

        return $headers;
    }

    /**
     * The non-blank records of the source keyed by their 1-based number.
     *
     * @return \Generator<int, list<string>>
     */
    private function records(string $path, SourceSettings $settings): \Generator
    {
        $records = SourceFormat::Csv === $settings->format
            ? $this->csvRecords($path, $settings->encoding ?? Encoding::Utf8, $settings->delimiter ?? ',')
            : $this->xlsxRecords($path, $settings->sheet);

        foreach ($records as $number => $cells) {
            if ([] !== array_filter($cells, static fn (string $cell): bool => '' !== trim($cell))) {
                yield $number => $cells;
            }
        }
    }

    /**
     * Takes the apostrophe off a cell that an export protected against formulas.
     */
    private function unquote(string $cell): string
    {
        return \strlen($cell) > 1 && "'" === $cell[0] && str_contains("=+-@\t\r", $cell[1]) ? substr($cell, 1) : $cell;
    }

    /**
     * @return \Generator<int, list<string>>
     */
    private function csvRecords(string $path, Encoding $encoding, string $delimiter): \Generator
    {
        $handle = fopen($path, 'rb');
        if (false === $handle) {
            throw SourceRejected::unknownFormat();
        }

        try {
            if (self::UTF8_BOM !== fread($handle, 3)) {
                rewind($handle);
            }

            $number = 0;
            while (($record = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                ++$number;

                yield $number => array_map(
                    fn (?string $cell): string => $this->decodeCell($cell ?? '', $encoding, $number),
                    $record,
                );
            }
        } finally {
            fclose($handle);
        }
    }

    private function decodeCell(string $cell, Encoding $encoding, int $row): string
    {
        // Never substitute silently: a UTF-8 file with stray bytes is probably in another encoding.
        if ((Encoding::Utf8 === $encoding || Encoding::Utf8Bom === $encoding) && !mb_check_encoding($cell, 'UTF-8')) {
            throw SourceRejected::invalidEncoding($row);
        }

        return $this->textDecoder->decode($cell, $encoding);
    }

    /**
     * @return \Generator<int, list<string>>
     */
    private function xlsxRecords(string $path, ?string $sheetName): \Generator
    {
        $reader = $this->openXlsx($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if (null !== $sheetName && $sheet->getName() !== $sheetName) {
                    continue;
                }

                // Empty rows are preserved, so the rows are numbered without gaps.
                $number = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    ++$number;

                    yield $number => array_map(CellText::of(...), $row->toArray());
                }

                return;
            }

            // No sheet matched the name.
            throw SourceRejected::unknownFormat();
        } catch (IOException) {
            throw SourceRejected::unknownFormat();
        } finally {
            $reader->close();
        }
    }

    /**
     * @return list<string>
     */
    private function sheetNames(string $path): array
    {
        $reader = $this->openXlsx($path);
        $names = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $names[] = $sheet->getName();
            }
        } catch (IOException) {
            throw SourceRejected::unknownFormat();
        } finally {
            $reader->close();
        }

        return $names;
    }

    private function openXlsx(string $path): XlsxReader
    {
        $options = new Options();
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new XlsxReader($options);

        try {
            $reader->open($path);
        } catch (IOException) {
            throw SourceRejected::unknownFormat();
        }

        return $reader;
    }

    private function detectFormat(string $path): SourceFormat
    {
        $size = is_file($path) ? filesize($path) : false;
        if (false === $size) {
            throw SourceRejected::unknownFormat();
        }
        if (0 === $size) {
            throw SourceRejected::empty();
        }
        if ($size > $this->maxFileBytes) {
            throw SourceRejected::tooLarge($this->maxFileBytes);
        }

        $head = (string) file_get_contents($path, false, null, 0, 8192);
        if (str_starts_with($head, "PK\x03\x04")) {
            $this->guardArchive($path);

            return SourceFormat::Xlsx;
        }
        if (str_contains($head, "\0")) {
            throw SourceRejected::unknownFormat();
        }

        return SourceFormat::Csv;
    }

    /**
     * Refuses archives that are no workbook or that would unpack into
     * something much larger than the file itself.
     */
    private function guardArchive(string $path): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            throw SourceRejected::unknownFormat();
        }

        try {
            $total = 0;
            $isWorkbook = false;
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $entry = $zip->statIndex($index);
                if (false === $entry) {
                    throw SourceRejected::unknownFormat();
                }
                $isWorkbook = $isWorkbook || 'xl/workbook.xml' === $entry['name'];
                $total += $entry['size'];
                if ($total > $this->maxUncompressedBytes) {
                    throw SourceRejected::archiveTooLarge($this->maxUncompressedBytes);
                }
                if ($entry['size'] > $this->maxCompressionRatio * max(1, $entry['comp_size'])) {
                    throw SourceRejected::suspiciousCompression($this->maxCompressionRatio);
                }
            }

            if (!$isWorkbook) {
                throw SourceRejected::unknownFormat();
            }
        } finally {
            $zip->close();
        }
    }
}
