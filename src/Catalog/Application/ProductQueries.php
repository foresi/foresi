<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Application;

use Foresi\Catalog\Domain\Product;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The products a view of the list holds.
 */
interface ProductQueries
{
    /**
     * The products the filter holds, in name order, backwards when asked; the
     * archived ones only when a condition of the filter asks for them. A text narrows
     * them to those whose name or description contains it, whatever its case.
     *
     * @return list<Product>
     */
    public function index(ViewFilter $filter, bool $descending = false, string $text = ''): array;
}
