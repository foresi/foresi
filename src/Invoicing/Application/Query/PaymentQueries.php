<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application\Query;

/**
 * Reads the payments of the active tenant for the API. Anything of another
 * tenant does not exist for it. Call it through PaymentList and PaymentDetails,
 * which ask who may.
 */
interface PaymentQueries
{
    /**
     * @param int $limit at most this many rows
     *
     * @return list<PaymentRow> newest first, only those older than the cursor
     */
    public function after(PaymentSliceFilter $filter, ?string $afterId, int $limit): array;

    public function find(string $id): ?PaymentRow;
}
