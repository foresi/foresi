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
    public function find(string $documentId): ?DocumentReading;

    /**
     * The reading as stored now, a copy loaded before detached. Call it after
     * the document is locked, so whoever finishes the reading waits for the one
     * before and sees what it stored.
     */
    public function fresh(string $documentId): ?DocumentReading;

    public function add(DocumentReading $reading): void;
}
