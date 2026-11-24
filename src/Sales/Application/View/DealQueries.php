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
     * in the trash only when a condition of the filter asks for them. A text narrows
     * them to those whose title contains it, whatever its case, or whose client is one
     * of $clientIds (the clients whose name the same text matches).
     *
     * @param list<string> $clientIds
     *
     * @return list<Deal>
     */
    public function index(ViewFilter $filter, bool $descending = false, string $text = '', array $clientIds = []): array;
}
