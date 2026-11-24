<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Reading;

use Foresi\Party\Domain\Party;
use Foresi\Purchasing\Domain\SupplierSnapshot;

/**
 * Whom a reading names as the supplier: one active party with the snapshot a
 * draft takes of it, or none with the warning that says why (a code of
 * ReceivedDocumentWarning). A reading that names nobody is none without a
 * warning: there was nothing to look for.
 */
final readonly class SupplierMatch
{
    public function __construct(
        public ?Party $party = null,
        public ?string $warning = null,
        public ?SupplierSnapshot $snapshot = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }
}
