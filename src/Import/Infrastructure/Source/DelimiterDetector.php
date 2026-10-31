<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Source;

/**
 * Finds the delimiter of a CSV sample: the candidate that splits the first
 * records into the same non-zero number of cells most often.
 */
final readonly class DelimiterDetector
{
    private const array CANDIDATES = [';', ',', "\t", '|'];
    private const int RECORDS = 20;
    private const string FALLBACK = ',';

    public function detect(string $sample): string
    {
        $tallies = [];
        foreach (self::CANDIDATES as $candidate) {
            $tallies[$candidate] = [];
        }

        foreach ($this->records($sample) as $counts) {
            foreach ($counts as $candidate => $count) {
                if ($count > 0) {
                    $tallies[$candidate][$count] = ($tallies[$candidate][$count] ?? 0) + 1;
                }
            }
        }

        $best = self::FALLBACK;
        $bestScore = [0, 0];
        foreach ($tallies as $candidate => $tally) {
            foreach ($tally as $count => $records) {
                $score = [$records, $count];
                if ($score > $bestScore) {
                    $best = $candidate;
                    $bestScore = $score;
                }
            }
        }

        return $best;
    }

    /**
     * The delimiter counts of each of the first records, ignoring delimiters
     * and line breaks inside quotes.
     *
     * @return list<array<string, int>>
     */
    private function records(string $sample): array
    {
        $records = [];
        $counts = array_fill_keys(self::CANDIDATES, 0);
        $length = 0;
        $quoted = false;

        foreach (str_split($sample) as $char) {
            if ('"' === $char) {
                $quoted = !$quoted;
            } elseif (!$quoted && ("\n" === $char || "\r" === $char)) {
                if ($length > 0) {
                    $records[] = $counts;
                    if (self::RECORDS === \count($records)) {
                        return $records;
                    }
                }
                $counts = array_fill_keys(self::CANDIDATES, 0);
                $length = 0;

                continue;
            } elseif (!$quoted && isset($counts[$char])) {
                ++$counts[$char];
            }
            ++$length;
        }

        if ($length > 0 && \count($records) < self::RECORDS) {
            $records[] = $counts;
        }

        return $records;
    }
}
