<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application;

use Foresi\Deals\Contract\BillingDocument;
use Foresi\Deals\Domain\BillingPlanEntry;
use Foresi\Deals\Domain\DealLine;
use Foresi\Shared\Domain\Money;

/**
 * One planned invoice as it is read: its amount (a recurring entry's from its
 * line), the line it follows, the document that fulfils it, and its state.
 */
final readonly class BillingEntryView
{
    public const string PLANNED = 'planned';
    public const string OVERDUE = 'overdue';
    public const string INVOICED = 'invoiced';

    /**
     * @param Money|null           $amount   of one invoice; null for a recurring entry whose line has no price
     * @param BillingDocument|null $document the invoice or recurring invoice that fulfils it, when the module that issues them still finds it
     * @param string               $state    planned, overdue (its day has passed and nothing fulfils it) or invoiced
     */
    public function __construct(
        public BillingPlanEntry $entry,
        public ?Money $amount,
        public ?DealLine $line,
        public ?BillingDocument $document,
        public string $state,
    ) {
    }
}
