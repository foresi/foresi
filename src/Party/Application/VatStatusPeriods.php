<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\VatStatusPeriod;

/**
 * The stored VAT statuses of the firm. Call add() and remove() inside
 * TransactionalWork, which flushes.
 */
interface VatStatusPeriods
{
    /**
     * Oldest first, the undated one first.
     *
     * @return list<VatStatusPeriod>
     */
    public function all(): array;

    public function get(string $id): ?VatStatusPeriod;

    public function add(VatStatusPeriod $period): void;

    public function remove(VatStatusPeriod $period): void;
}
