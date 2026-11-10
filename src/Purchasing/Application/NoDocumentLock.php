<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Purchasing\Domain\ReceivedDocument;

/**
 * Nothing closes a month yet, so no document is locked. The month close
 * replaces it.
 */
final readonly class NoDocumentLock implements DocumentLock
{
    public function isLocked(ReceivedDocument $document): bool
    {
        return false;
    }
}
