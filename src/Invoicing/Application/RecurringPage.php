<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Domain\RecurringInvoice;

/**
 * One page of recurring templates: the soonest next date first, ended ones last.
 */
final readonly class RecurringPage
{
    /**
     * @param list<RecurringInvoice> $templates
     * @param list<string>           $failing   the ids of those on this page that are failing now: not
     *                                          ended, with a refused issue, or a refused send whose invoice
     *                                          still waits to be sent
     * @param array<string, int>     $counts    the views of RecurringFilter::$counted, by key
     */
    public function __construct(
        public array $templates,
        public int $total,
        public int $page,
        public int $pages,
        public array $failing = [],
        public array $counts = [],
    ) {
    }

    public function isFailing(RecurringInvoice $template): bool
    {
        return \in_array($template->id(), $this->failing, true);
    }
}
