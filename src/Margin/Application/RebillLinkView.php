<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

/**
 * The planned item a recurring invoice line rebills, as a person sees it: the
 * item (its name is its id when Cash flow no longer knows it), the day the
 * link was made and the warnings that do not stop it. The only warning is
 * RebillLinks::CUSTOMER_NOT_A_RECIPIENT: the customer of the template bears no
 * share of the item's mapping this month, so no invoice would be paired.
 */
final readonly class RebillLinkView
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public string $recurringLineId,
        public string $plannedItemId,
        public string $plannedItemName,
        public ?string $plannedItemUrl,
        public string $createdOn,
        public array $warnings,
    ) {
    }
}
