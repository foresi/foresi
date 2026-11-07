<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application\Import;

use App\Invoicing\Application\SeriesCatalog;
use App\Invoicing\Domain\Series;
use App\Party\Domain\LegalEntity;

/**
 * A name for a series an import creates. Names are the firm's, across the kinds
 * and its archived series too, whatever their case; iDoklad names its sequences
 * per type, so several types share one ("Default"). A name taken gets the first
 * free number after it ("Default 2"), shortened to fit when it is long.
 */
final readonly class SeriesNames
{
    public function __construct(private SeriesCatalog $catalog)
    {
    }

    public function free(LegalEntity $firm, string $name): string
    {
        $taken = array_map(static fn (Series $series): string => mb_strtolower($series->name()), $this->catalog->everyForFirm($firm));
        $candidate = mb_substr($name, 0, Series::MAX_NAME_LENGTH);
        for ($number = 2; \in_array(mb_strtolower($candidate), $taken, true); ++$number) {
            $suffix = ' '.$number;
            $candidate = mb_substr($name, 0, Series::MAX_NAME_LENGTH - \strlen($suffix)).$suffix;
        }

        return $candidate;
    }
}
