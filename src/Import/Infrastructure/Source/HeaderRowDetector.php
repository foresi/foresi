<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Source;

/**
 * Finds the header among the first rows of a source: the first row that is at
 * least 60 % filled, relative to the widest of those rows, and holds no cell
 * that is a bare number. Title lines above the table are skipped that way.
 */
final readonly class HeaderRowDetector
{
    private const int ROWS = 10;
    private const int MINIMUM_FILLED_PERCENT = 60;

    /**
     * @param array<int, list<string>> $rows source rows keyed by their 1-based number
     *
     * @return int the 1-based source row number of the header
     */
    public function detect(array $rows): int
    {
        $candidates = array_filter($rows, static fn (int $number): bool => $number <= self::ROWS, \ARRAY_FILTER_USE_KEY);
        if ([] === $candidates) {
            return 1;
        }

        // The widest row sets the width: a single stray wide row can hide a true header, which then falls back to the first filled row.
        $width = max(array_map(\count(...), $candidates));
        $firstFilled = null;

        foreach ($candidates as $number => $cells) {
            $filled = \count(array_filter($cells, static fn (string $cell): bool => '' !== trim($cell)));
            if (0 === $filled) {
                continue;
            }
            $firstFilled ??= $number;

            if ($filled * 100 >= self::MINIMUM_FILLED_PERCENT * $width && !$this->hasNumericCell($cells)) {
                return $number;
            }
        }

        return $firstFilled ?? 1;
    }

    /**
     * @param list<string> $cells
     */
    private function hasNumericCell(array $cells): bool
    {
        return array_any($cells, fn (string $cell): bool => 1 === preg_match('/^[+-]?\d+(?:[.,]\d+)?$/', trim($cell)));
    }
}
