<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\RecurringInvoice;

/**
 * One page of recurring templates: the soonest next date first, ended ones last.
 */
final readonly class RecurringPage
{
    /**
     * @param list<RecurringInvoice> $templates
     */
    public function __construct(
        public array $templates,
        public int $total,
        public int $page,
        public int $pages,
    ) {
    }
}
