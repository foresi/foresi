<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Shared\Domain\Money;

/**
 * One effective line of a cost document with its own mapping, if any, and
 * the shares that follow from it.
 */
final readonly class LineAllocationView
{
    /**
     * @param list<ShareView> $shares
     */
    public function __construct(
        public string $key,
        public string $text,
        public ?Money $baseCzk,
        public ?MappingView $mapping,
        public array $shares,
    ) {
    }
}
