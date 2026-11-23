<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Contract;

use Foresi\Shared\Domain\Money;

/**
 * One line of a cost document: a stable key, its text and its base without
 * VAT in CZK, null while the document has no exchange rate.
 */
final readonly class CostLine
{
    public function __construct(
        public string $key,
        public string $text,
        public ?Money $baseCzk,
    ) {
    }
}
