<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Application\Source\SourceFormat;
use App\Import\Domain\FieldDefinition;

/**
 * Writes exported records as a spreadsheet file. The header is the translated
 * label of every field, so the file maps back onto the same fields on import,
 * and cells that a spreadsheet would run as a formula are neutralised.
 */
interface ExportWriter
{
    /**
     * @param list<FieldDefinition>           $fields the columns, in order
     * @param iterable<array<string, string>> $rows   values by field key
     * @param string                          $path   a file, or php://output to stream
     */
    public function write(array $fields, iterable $rows, SourceFormat $format, string $path): void;
}
