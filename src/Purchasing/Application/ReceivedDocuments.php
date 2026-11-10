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

    public function add(ReceivedDocument $document): void;
}
