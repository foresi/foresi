<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Reading;

use Foresi\Party\Domain\Party;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Domain\SupplierSnapshot;

/**
 * The supplier one save writes into a draft: a matched party with its
 * snapshot, or, with no party, the snapshot of what the reading says. The
 * fields are what the reading gave, for the record of what was applied.
 *
 * @internal
 */
final readonly class SupplierWrite
{
    /**
     * @param list<ReadField> $fields
     */
    public function __construct(
        public ?Party $party,
        public SupplierSnapshot $snapshot,
        public array $fields,
    ) {
    }
}
