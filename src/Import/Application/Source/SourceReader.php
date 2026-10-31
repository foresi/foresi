<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Source;

/**
 * Reads tabular source files (CSV and XLSX) for import.
 */
interface SourceReader
{
    /**
     * Detects the layout of the file. Parts given in the override replace the
     * detected ones; the format is always detected from the content.
     *
     * @throws SourceRejected
     */
    public function inspect(string $path, ?SourceSettings $override = null): SourceInspection;

    /**
     * Streams the data rows below the header as UTF-8 text, keyed by their
     * 1-based source row number. Fully empty rows are skipped. Settings left
     * null are detected from the file.
     *
     * @return iterable<int, list<string>>
     *
     * @throws SourceRejected
     */
    public function rows(string $path, SourceSettings $settings): iterable;
}
