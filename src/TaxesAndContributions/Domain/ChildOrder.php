<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Domain;

/**
 * The order of the children a year claims. The children go by birth date,
 * the eldest first, and each takes the order of its place: 1, 2, and 3 for the
 * third and further. The owner may fix another order for a child, because the
 * parents of one household agree which of them claims which child as which,
 * and a child with a severe disability card (ZTP/P) gets the most as the last
 * (GFŘ D-59 to § 35c of the Income Tax Act). An order fixed to the one the
 * birth date gives is no choice at all, so it follows the birth date again.
 */
final class ChildOrder
{
    /**
     * The children by birth date, each as its key in $births with its order
     * and whether the owner fixed it.
     *
     * @param array<int, \DateTimeImmutable> $births the birth date of each child, by key
     * @param array<int, ?int>               $fixed  the order the owner gave a child, null to follow the birth date
     *
     * @return list<array{key: int, order: int, chosen: bool}>
     */
    public static function of(array $births, array $fixed): array
    {
        $keys = array_keys($births);
        usort($keys, static fn (int $a, int $b): int => [$births[$a], $a] <=> [$births[$b], $b]);
        $ordered = [];
        foreach ($keys as $place => $key) {
            $automatic = min(3, $place + 1);
            $given = $fixed[$key] ?? null;
            $chosen = null !== $given && $given !== $automatic;
            $ordered[] = ['key' => $key, 'order' => $chosen ? $given : $automatic, 'chosen' => $chosen];
        }

        return $ordered;
    }
}
