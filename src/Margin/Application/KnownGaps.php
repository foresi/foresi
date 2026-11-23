<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Margin\Contract\CostDocument;

/**
 * What the margin cannot count yet. A document with a line that has no base
 * in CZK, because the exchange rate of a foreign document is missing, has no
 * share for that line; it is reported here as missing_exchange_rate instead
 * of counting as zero. A document in the trash is no gap.
 */
final readonly class KnownGaps
{
    public const string MISSING_EXCHANGE_RATE = 'missing_exchange_rate';

    public function __construct(private CostSources $sources)
    {
    }

    public function missingExchangeRate(string $documentId): bool
    {
        $document = $this->sources->document($documentId);

        return $document instanceof CostDocument && !$document->trashed && $this->hasLineWithoutBase($document);
    }

    /**
     * @param iterable<string> $documentIds
     *
     * @return list<string> the ids that have the gap, in the order given
     */
    public function documentsMissingExchangeRate(iterable $documentIds): array
    {
        $gaps = [];
        foreach ($documentIds as $id) {
            if ($this->missingExchangeRate($id)) {
                $gaps[] = $id;
            }
        }

        return $gaps;
    }

    private function hasLineWithoutBase(CostDocument $document): bool
    {
        return array_any($document->lines, fn (\Foresi\Margin\Contract\CostLine $line): bool => !$line->baseCzk instanceof \Foresi\Shared\Domain\Money);
    }
}
