<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Margin\Contract\CostDocument;
use Foresi\Margin\Contract\CostLine;
use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\AllocationKind;
use Foresi\Margin\Domain\Recipient;
use Foresi\Margin\Domain\ShareSplitter;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * A fixed mapping of a planned item holds per occurrence, not per line: the
 * documents of one occurrence, by their month basis and then by id, fill the
 * fixed amounts with their lines in order, and what is left of each line
 * goes to the share marked rest. So 600 CZK to a client is 600 CZK for the
 * month, however many documents it came in.
 *
 * A line with a negative base (a credit note) gives nothing back to the
 * amounts; it is split on its own by the rule of a line mapping.
 */
final readonly class OccurrenceFill
{
    public function __construct(private ShareSplitter $splitter)
    {
    }

    /**
     * @param list<array{CostDocument, list<CostLine>}> $documents each document of the occurrence with the lines the item's mapping applies to
     *
     * @return array<string, array<string, list<array{Recipient, Money}>>> the parts of each line, by document id and line key, in the order of the shares
     */
    public function split(Allocation $allocation, array $documents): array
    {
        if (AllocationKind::Fixed !== $allocation->kind) {
            throw new \InvalidArgumentException('Only a fixed mapping is filled per occurrence.');
        }
        usort($documents, static fn (array $a, array $b): int => [$a[0]->monthBasis->getTimestamp(), $a[0]->id] <=> [$b[0]->monthBasis->getTimestamp(), $b[0]->id]);

        $left = array_map(static fn (\Foresi\Margin\Domain\AllocationShare $share): int => $share->rest ? 0 : (int) $share->amountCzk?->minor(), $allocation->shares);
        $parts = [];
        foreach ($documents as [$document, $lines]) {
            foreach ($lines as $line) {
                if (!$line->baseCzk instanceof Money) {
                    continue;
                }
                $base = $line->baseCzk->minor();
                if ($base < 0) {
                    $parts[$document->id][$line->key] = $this->splitter->split($allocation, $line->baseCzk);
                    continue;
                }
                $parts[$document->id][$line->key] = $this->fill($allocation, $base, $left);
            }
        }

        return $parts;
    }

    /**
     * @param array<int, int> $left what each fixed share still has to get, by its position; lowered by what this line gives
     *
     * @return list<array{Recipient, Money}>
     */
    private function fill(Allocation $allocation, int $base, array &$left): array
    {
        $amounts = [];
        $rest = null;
        foreach ($allocation->shares as $index => $share) {
            if ($share->rest) {
                $rest = $index;
                $amounts[$index] = 0;
                continue;
            }
            $amount = min($left[$index], $base);
            $amounts[$index] = $amount;
            $left[$index] -= $amount;
            $base -= $amount;
        }
        if (null !== $rest) {
            $amounts[$rest] = $base;
        }

        $parts = [];
        foreach ($allocation->shares as $index => $share) {
            $parts[] = [$share->recipient, Money::ofMinor($amounts[$index], Currency::CZK)];
        }

        return $parts;
    }
}
