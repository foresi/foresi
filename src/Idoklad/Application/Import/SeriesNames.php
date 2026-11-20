<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Application\Import;

use Foresi\Invoicing\Application\SeriesCatalog;
use Foresi\Invoicing\Domain\Series;

/**
 * A name for a series an import creates. Names are unique across the kinds
 * and its archived series too, whatever their case; iDoklad names its sequences
 * per type, so several types share one ("Default"). A name taken gets the first
 * free number after it ("Default 2"), shortened to fit when it is long.
 */
final readonly class SeriesNames
{
    public function __construct(private SeriesCatalog $catalog)
    {
    }

    public function free(string $name): string
    {
        $taken = array_map(static fn (Series $series): string => mb_strtolower($series->name()), $this->catalog->every());
        $candidate = mb_substr($name, 0, Series::MAX_NAME_LENGTH);
        for ($number = 2; \in_array(mb_strtolower($candidate), $taken, true); ++$number) {
            $suffix = ' '.$number;
            $candidate = mb_substr($name, 0, Series::MAX_NAME_LENGTH - \strlen($suffix)).$suffix;
        }

        return $candidate;
    }
}
