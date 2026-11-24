<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Domain;

/**
 * Where the reading of a received document's file stands: queued or running,
 * read with values found, read with nothing found, or failed because no reader
 * could read the file (or the job gave up).
 */
enum ReadingState: string
{
    case Reading = 'reading';
    case Read = 'read';
    case NothingFound = 'nothing_found';
    case Failed = 'failed';
}
