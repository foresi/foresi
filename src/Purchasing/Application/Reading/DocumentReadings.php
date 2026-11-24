<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Reading;

use Foresi\Purchasing\Domain\DocumentReading;

/**
 * The stored readings, one per received document.
 */
interface DocumentReadings
{
    /**
     * Lock the document first (ReceivedDocuments::lock()) and only then read
     * and change its reading: the lock reads the reading again, so whoever
     * settles a reading waits for the one before and sees what it stored. A
     * reading changed before the lock and not yet saved would be lost; a lock
     * after a reading was added in the same unit is refused with a LogicException.
     */
    public function find(string $documentId): ?DocumentReading;

    public function add(DocumentReading $reading): void;
}
