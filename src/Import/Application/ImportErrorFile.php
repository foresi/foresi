<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Application\Source\SourceFormat;
use App\Import\Application\Source\SourceSettings;
use App\Import\Domain\RowOutcome;

/** The error file of a checked or finished import. */
final readonly class ImportErrorFile
{
    public function __construct(
        private Imports $imports,
        private ErrorFileWriter $writer,
    ) {
    }

    /**
     * @return SourceFormat the format written, the same as the uploaded file
     *
     * @throws ImportRefused when the staged rows are already purged
     */
    public function write(int $importId, string $locale, string $path): SourceFormat
    {
        $import = $this->imports->find($importId) ?? throw ImportRefused::notFound();
        if (null === $import->fileKey()) {
            throw new ImportRefused('import.refused.rows_purged');
        }
        $this->writer->write($import, $this->imports->rowsWithOutcome($import, RowOutcome::Error), $locale, $path);

        return SourceSettings::fromArray($import->sourceSettings())->format;
    }
}
