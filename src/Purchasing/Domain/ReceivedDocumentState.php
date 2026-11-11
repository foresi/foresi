<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Domain;

/**
 * A draft is a file uploaded and still to be completed; a recorded document is
 * complete, numbered in the evidence series and counts in the reports. A
 * recorded document never goes back to a draft.
 */
enum ReceivedDocumentState: string
{
    case Draft = 'draft';
    case Recorded = 'recorded';
}
