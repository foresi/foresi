<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application;

use Foresi\Purchasing\Domain\ReceivedDocument;

/**
 * The received documents, the trash included. Lists and
 * details for the screens and the API come from query services, not from here.
 */
interface ReceivedDocuments
{
    public function find(string $id): ?ReceivedDocument;

    /**
     * The documents with these ids, in one query; an unknown or invalid id is left out.
     *
     * @param list<string> $ids
     *
     * @return list<ReceivedDocument>
     */
    public function findMany(array $ids): array;

    /**
     * The document, its row locked until the transaction ends and read again,
     * so whoever changes it waits for the one before; a copy loaded before is
     * detached. Call it inside a unit of work and before anything is read in
     * it, as EvidenceNumbers asks.
     *
     * @throws UnknownReceivedDocument
     */
    public function lock(string $id): ReceivedDocument;

    public function add(ReceivedDocument $document): void;

    /**
     * Removes the document for good, saved with the unit of work.
     */
    public function remove(ReceivedDocument $document): void;
}
