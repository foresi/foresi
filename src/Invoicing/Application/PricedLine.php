<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Domain\LineAmounts;
use Foresi\Invoicing\Domain\LineValues;

/**
 * A line after the regime and the tax point had their say: what is stored.
 */
final readonly class PricedLine
{
    public function __construct(public LineInput $input, public LineValues $values, public LineAmounts $amounts)
    {
    }
}
