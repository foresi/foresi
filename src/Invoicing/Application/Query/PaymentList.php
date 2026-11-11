<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application\Query;

use App\Shared\Domain\Id;

/**
 * The list of payments for the API and MCP, read by cursor. Only the owners and
 * members of the organisation read it.
 */
final readonly class PaymentList
{
    public const int DEFAULT_LIMIT = 25;
    public const int MAX_LIMIT = 100;

    public function __construct(private PaymentAccess $access, private PaymentQueries $queries)
    {
    }

    /**
     * The newest payments first, those after the cursor. A cursor that is no
     * payment id lists nothing, and a limit is kept between 1 and MAX_LIMIT.
     *
     * @param string|null $afterId the id of the last row of the slice before; null for the first slice
     *
     * @throws PaymentsNotAllowed for anyone but the owners and members of the organisation
     */
    public function slice(PaymentSliceFilter $filter, ?string $afterId = null, int $limit = self::DEFAULT_LIMIT): PaymentSlice
    {
        $this->access->denyUnlessStaff();
        if (null !== $afterId && !Id::isValid($afterId)) {
            return new PaymentSlice([], false);
        }
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $rows = $this->queries->after($filter, $afterId, $limit + 1);

        return new PaymentSlice(\array_slice($rows, 0, $limit), \count($rows) > $limit);
    }
}
