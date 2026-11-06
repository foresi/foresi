<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Domain;

/**
 * The number format of an iDoklad numeric sequence in the tokens of a Foresi
 * series. iDoklad writes R for the year, M for the month and N for the number;
 * RRRR is the year with four digits, RR with two, MM the month, and the count
 * of N the width of the number. Anything else is text, which the series format
 * checks. A run of R or M of another length means nothing certain, so it is
 * refused rather than guessed.
 */
final class IdokladNumberFormat
{
    private const array RUNS = ['RRRR' => '{YYYY}', 'RR' => '{YY}', 'MM' => '{MM}'];

    /**
     * @return string|null the Foresi format, or null when it cannot be read
     */
    public static function toSeries(string $format): ?string
    {
        preg_match_all('/R+|M+|N+|[^RMN]+/', $format, $matches);
        $series = '';
        foreach ($matches[0] as $part) {
            if (str_starts_with($part, 'N')) {
                $series .= '{'.$part.'}';
            } elseif (!str_contains('RM', $part[0])) {
                $series .= $part;
            } elseif (isset(self::RUNS[$part])) {
                $series .= self::RUNS[$part];
            } else {
                return null;
            }
        }

        return $series;
    }
}
