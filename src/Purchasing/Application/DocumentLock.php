<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Purchasing\Domain\ReceivedDocument;

/**
 * Whether a received document no longer takes a change, a move to the trash or
 * a restore, because what it is part of was closed: the month close will lock
 * the documents of a month handed to the accountant. The use cases refuse a
 * locked document with LOCKED.
 */
interface DocumentLock
{
    public function isLocked(ReceivedDocument $document): bool;
}
