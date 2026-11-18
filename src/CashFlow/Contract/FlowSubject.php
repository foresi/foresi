<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * What an expected flow comes from, such as an invoice or a planned item, so
 * any number of the forecast can be traced to its record.
 */
final readonly class FlowSubject
{
    /**
     * @param string      $type such as "invoice" or "planned_item", lower snake case
     * @param string|null $url  where the API reads it, when it has a place there
     */
    public function __construct(
        public string $type,
        public string $id,
        public ?string $url = null,
    ) {
    }
}
