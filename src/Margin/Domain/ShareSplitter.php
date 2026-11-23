<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

use Brick\Math\BigInteger;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * Splits the base of a cost, in CZK without VAT, among the recipients of an
 * allocation in whole hellers, so the shares always add up to the base.
 *
 * Percent: each share is the floor of base x percent / 100, and the
 * remainder goes to the last share in the typed order. Fixed: the fixed
 * amounts in order, each capped at what is left, and the rest to the share
 * marked rest. A negative base (a credit note) is split by the same rule on
 * its size and gives negative shares.
 */
final readonly class ShareSplitter
{
    /**
     * @return list<array{Recipient, Money}> in the order of the shares
     *
     * @throws \InvalidArgumentException when the base is not in CZK
     */
    public function split(Allocation $allocation, Money $baseCzk): array
    {
        if (Currency::CZK !== $baseCzk->currency()) {
            throw new \InvalidArgumentException('The base of a share is in CZK.');
        }
        $minor = $baseCzk->minor();
        $sign = $minor < 0 ? -1 : 1;
        $size = abs($minor);

        $amounts = match ($allocation->kind) {
            AllocationKind::Whole => [$size],
            AllocationKind::Percent => $this->percent($allocation, $size),
            AllocationKind::Fixed => $this->fixed($allocation, $size),
        };

        $parts = [];
        foreach ($allocation->shares as $index => $share) {
            $parts[] = [$share->recipient, Money::ofMinor($sign * $amounts[$index], Currency::CZK)];
        }

        return $parts;
    }

    /**
     * @return list<int>
     */
    private function percent(Allocation $allocation, int $size): array
    {
        $amounts = [];
        $given = 0;
        foreach ($allocation->shares as $share) {
            $hundredths = (int) round((float) $share->percent * 100);
            $amount = BigInteger::of($size)->multipliedBy($hundredths)->quotient(10000)->toInt();
            $amounts[] = $amount;
            $given += $amount;
        }
        $amounts[\count($amounts) - 1] += $size - $given;

        return array_values($amounts);
    }

    /**
     * @return list<int>
     */
    private function fixed(Allocation $allocation, int $size): array
    {
        $left = $size;
        $amounts = [];
        $rest = null;
        foreach ($allocation->shares as $index => $share) {
            if ($share->rest || null === $share->amountCzk) {
                $rest = $index;
                $amounts[] = 0;
                continue;
            }
            $amount = min($share->amountCzk->minor(), $left);
            $amounts[] = $amount;
            $left -= $amount;
        }
        if (null !== $rest) {
            $amounts[$rest] = $left;
        }

        return array_values($amounts);
    }
}
