<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Shared\Domain\Money;

/**
 * One document behind a cell. Revenue: the source is "revenue", the kind is
 * the document kind and the amount is its net. Cost: the source is the
 * CostShareSource value of the share (line, document, planned_item, supplier,
 * unassigned), the kind is null, the text is the line it comes from and the
 * amount is the share. The label and the url are what the person sees of the
 * document; the url is null when its module cannot show it any more. A share
 * from a planned item names the item and the day of the occurrence.
 */
final readonly class MarginCellItem
{
    public const string REVENUE = 'revenue';

    public function __construct(
        public string $documentId,
        public string $label,
        public ?string $text,
        public Money $amountCzk,
        public string $source,
        public ?string $kind,
        public ?string $url,
        public ?string $plannedItemId = null,
        public ?string $occurrenceOn = null,
    ) {
    }
}
