<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\View;

use Foresi\Sales\Domain\Deal;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The deals a view of the list holds.
 */
interface DealQueries
{
    /**
     * The deals the filter holds, in title order, backwards when asked; those
     * in the trash only when a condition of the filter asks for them.
     *
     * @return list<Deal>
     */
    public function index(ViewFilter $filter, bool $descending = false): array;
}
