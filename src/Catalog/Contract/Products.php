<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Contract;

/**
 * The products of the catalog, read by other modules (ADR 0033). Who may see
 * them is the caller's to decide: the catalog is staff-only on its own screens.
 */
interface Products
{
    /**
     * @throws UnknownProduct
     */
    public function get(string $id): ProductSnapshot;

    /**
     * The products whose name or description holds $text, all of them for an
     * empty text, in name order. Archived products only when asked for.
     *
     * @return list<ProductSnapshot>
     */
    public function search(string $text, bool $includeArchived = false): array;
}
