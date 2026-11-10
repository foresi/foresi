<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application\Query;

use App\Invoicing\Application\Query\PaymentQueries;
use App\Invoicing\Application\Query\PaymentRow;
use App\Invoicing\Application\Query\PaymentSliceFilter;

/**
 * Remembers the limits it is asked for and finds nothing.
 */
final class PaymentLimitSpy implements PaymentQueries
{
    /** @var list<int> */
    public array $limits = [];

    public function after(PaymentSliceFilter $filter, ?string $afterId, int $limit): array
    {
        $this->limits[] = $limit;

        return [];
    }

    public function find(string $id): ?PaymentRow
    {
        return null;
    }
}
