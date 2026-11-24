<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

/**
 * What an invoice line rebills, as a person sees it: the occurrence of a
 * planned item, by its day. A pairing a person made is manual.
 */
final readonly class RebillPairingView
{
    public function __construct(
        public string $invoiceLineId,
        public string $invoiceId,
        public string $plannedItemId,
        public string $plannedItemName,
        public ?string $plannedItemUrl,
        public string $occurrenceOn,
        public bool $manual,
    ) {
    }
}
