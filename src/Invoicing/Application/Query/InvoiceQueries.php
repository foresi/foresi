<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application\Query;

/**
 * The reads of the lists the screens do not make.
 */
interface InvoiceQueries
{
    /**
     * At most $limit documents of the filter, newest first, whose id is below $afterId. The
     * ids are UUIDv7, so the order of the ids is the order of creation. A page of the
     * trash holds what is in it, wherever the filter's state points.
     *
     * @throws \InvalidArgumentException for a limit below one, or an id that is none
     */
    public function slice(InvoiceSliceFilter $filter, ?string $afterId, int $limit): InvoiceSlice;
}
