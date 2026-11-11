<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Purchasing\Domain\ReceivedDocument;

/**
 * The received documents of the active tenant, the trash included. Lists and
 * details for the screens and the API come from query services, not from here.
 */
interface ReceivedDocuments
{
    public function find(string $id): ?ReceivedDocument;

    /**
     * The document, its row locked until the transaction ends and read again,
     * so whoever changes it waits for the one before; a copy loaded before is
     * detached. Call it inside a unit of work and before anything is read in
     * it, as EvidenceNumbers asks.
     *
     * @throws UnknownReceivedDocument also for a document of another tenant
     */
    public function lock(string $id): ReceivedDocument;

    public function add(ReceivedDocument $document): void;

    /**
     * Removes the document for good, saved with the unit of work.
     */
    public function remove(ReceivedDocument $document): void;
}
