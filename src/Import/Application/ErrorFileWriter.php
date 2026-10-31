<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Domain\Import;
use App\Import\Domain\ImportRow;

/**
 * Writes the rows of an import that failed, so the user can fix them in the
 * source and upload them again: the original columns plus one column with the
 * messages, in the format of the uploaded file. Cells that a spreadsheet would
 * run as a formula are neutralised.
 */
interface ErrorFileWriter
{
    /**
     * @param iterable<ImportRow> $rows
     * @param string              $locale the language of the messages
     */
    public function write(Import $import, iterable $rows, string $locale, string $path): void;
}
