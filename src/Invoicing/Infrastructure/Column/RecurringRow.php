<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Column;

/**
 * One recurring invoice as its row shows it, every cell already in words: the
 * schedule, the next issue and the amount are worked out from the template, its
 * lines and today, which RecurringListController reads once for the whole page.
 */
final readonly class RecurringRow
{
    public function __construct(
        public string $name,
        public string $customer,
        public ?string $customerHref,
        public string $description,
        public string $interval,
        public string $next,
        public string $delivery,
        public string $amount,
        public string $deletedBy = '',
        public string $deletedAt = '',
    ) {
    }
}
