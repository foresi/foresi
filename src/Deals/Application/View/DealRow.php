<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application\View;

use Foresi\Deals\Application\DealValue;
use Foresi\Deals\Domain\Deal;

/**
 * One deal as a row of the list shows it: the deal and the value of its lines.
 */
final readonly class DealRow
{
    public function __construct(public Deal $deal, public DealValue $value)
    {
    }
}
