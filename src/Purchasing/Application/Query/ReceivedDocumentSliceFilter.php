<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Query;

use Foresi\Purchasing\Domain\ReceivedDocumentState;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * What a slice of received documents for the API and MCP holds: the documents
 * outside the trash, or only those in it, narrowed by state, whether
 * something remains to be paid and the day they are due before. An unpaid
 * document is a recorded one whose payments do not reach its total.
 *
 * The day of a document (its tax point, else its issue date, else the day it was received) may be limited to
 * dayFrom and dayTo, both included.
 *
 * A saved view and a filter (spec D13) narrow it besides; one on the trash decides
 * it instead of $trashed.
 */
final readonly class ReceivedDocumentSliceFilter
{
    public function __construct(
        public ?ReceivedDocumentState $state = null,
        public bool $unpaid = false,
        public ?\DateTimeImmutable $dueBefore = null,
        public bool $trashed = false,
        public ?string $supplierId = null,
        public ?ViewFilter $view = null,
        public ?\DateTimeImmutable $dayFrom = null,
        public ?\DateTimeImmutable $dayTo = null,
    ) {
    }
}
