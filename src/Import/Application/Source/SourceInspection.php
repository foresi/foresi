<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Source;

/**
 * What a source file looks like: the detected (or overridden) reading
 * settings, the header and the first data rows for the mapping screen.
 */
final readonly class SourceInspection
{
    /**
     * @param string|null              $delimiter    null for XLSX
     * @param list<string>             $sheets       sheet names; empty for CSV
     * @param string|null              $sheet        the sheet read; null for CSV
     * @param int                      $headerRow    1-based
     * @param list<string>             $headers      trimmed, BOM-stripped, empty ones named "Column N"
     * @param array<int, list<string>> $samples      the first data rows keyed by source row number
     * @param int                      $dataRowCount non-empty rows below the header
     */
    public function __construct(
        public SourceFormat $format,
        public ?Encoding $encoding,
        public ?string $delimiter,
        public array $sheets,
        public ?string $sheet,
        public int $headerRow,
        public array $headers,
        public array $samples,
        public int $dataRowCount,
    ) {
    }

    /**
     * The fully resolved settings, ready to be stored and handed to SourceReader::rows().
     */
    public function settings(): SourceSettings
    {
        return new SourceSettings($this->format, $this->encoding, $this->delimiter, $this->sheet, $this->headerRow);
    }
}
