<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

/**
 * The file a document list downloads as; the value is its extension.
 */
enum ListFileFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
}
