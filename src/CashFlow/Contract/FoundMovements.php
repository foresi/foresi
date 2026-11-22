<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * What a MovementFinder found.
 */
final readonly class FoundMovements
{
    /**
     * @param list<FoundMovement> $movements   oldest first, then by id
     * @param list<string>        $unavailable the keys of the actual sources that failed, once each
     */
    public function __construct(
        public array $movements,
        public array $unavailable = [],
    ) {
    }
}
