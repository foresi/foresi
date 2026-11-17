<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Query;

use App\Purchasing\Domain\ReceivedDocumentState;

/**
 * What a slice of received documents for the API and MCP holds: the documents
 * outside the trash, or only those in it, narrowed by firm, state, whether
 * something remains to be paid and the day they are due before. An unpaid
 * document is a recorded one whose payments do not reach its total.
 */
final readonly class ReceivedDocumentSliceFilter
{
    public function __construct(
        public ?string $firmId = null,
        public ?ReceivedDocumentState $state = null,
        public bool $unpaid = false,
        public ?\DateTimeImmutable $dueBefore = null,
        public bool $trashed = false,
        public ?string $supplierId = null,
    ) {
    }
}
