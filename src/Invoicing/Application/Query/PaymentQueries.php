<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Query;

/**
 * Reads the payments for the API. Call it through PaymentList and PaymentDetails,
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
