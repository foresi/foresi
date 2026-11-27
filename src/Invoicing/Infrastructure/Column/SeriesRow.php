<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Column;

use Foresi\Invoicing\Domain\Series;

/**
 * A number series as a row of the firm's invoicing settings: the series with its name as
 * shown and the number its next document gets.
 */
final readonly class SeriesRow
{
    public function __construct(public Series $series, public string $name, public string $next)
    {
    }
}
