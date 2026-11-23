<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\View;

use Foresi\Sales\Application\DealValue;
use Foresi\Sales\Domain\Deal;

/**
 * One deal as a row of the list shows it: the deal and the value of its lines.
 */
final readonly class DealRow
{
    public function __construct(public Deal $deal, public DealValue $value)
    {
    }
}
