<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Source;

/**
 * The file formats an import can be read from.
 */
enum SourceFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
}
