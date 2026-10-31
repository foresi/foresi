<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application\Source;

/**
 * The character encodings a CSV source can be decoded from.
 */
enum Encoding: string
{
    case Utf8 = 'utf-8';
    case Utf8Bom = 'utf-8-bom';
    case Windows1250 = 'windows-1250';
    case Iso88592 = 'iso-8859-2';
    case Windows1252 = 'windows-1252';
}
