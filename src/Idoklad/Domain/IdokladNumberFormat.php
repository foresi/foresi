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
 * of N the width of the number. The API documents bare runs ("RRRRNNNN"), and
 * accounts return them in braces ("Z9{RRRR}{NNNN}"), where all outside the
 * braces is text. Anything else is text, which the series format checks. A run
 * of R or M of another length means nothing certain, so it is refused rather
 * than guessed.
 */
final class IdokladNumberFormat
{
    private const array RUNS = ['RRRR' => '{YYYY}', 'RR' => '{YY}', 'MM' => '{MM}'];

    /**
     * @return string|null the Foresi format, or null when it cannot be read
     */
    public static function toSeries(string $format): ?string
    {
        if (str_contains($format, '{') || str_contains($format, '}')) {
            return self::braced($format);
        }
        preg_match_all('/R+|M+|N+|[^RMN]+/', $format, $matches);
        $series = '';
        foreach ($matches[0] as $part) {
            $token = str_contains('RMN', $part[0]) ? self::token($part) : $part;
            if (null === $token) {
                return null;
            }
            $series .= $token;
        }

        return $series;
    }

    /**
     * Tokens in braces, text between them; a brace without its pair is refused.
     */
    private static function braced(string $format): ?string
    {
        preg_match_all('/\{([^{}]*)\}|[^{}]+|[{}]/', $format, $matches, \PREG_SET_ORDER);
        $series = '';
        foreach ($matches as $match) {
            if (isset($match[1])) {
                $token = 1 === preg_match('/^(?:R+|M+|N+)$/D', $match[1]) ? self::token($match[1]) : null;
            } else {
                $token = str_contains('{}', $match[0]) ? null : $match[0];
            }
            if (null === $token) {
                return null;
            }
            $series .= $token;
        }

        return $series;
    }

    /** A run of one letter as its token; null for a run of R or M of another length. */
    private static function token(string $run): ?string
    {
        return 'N' === $run[0] ? '{'.$run.'}' : self::RUNS[$run] ?? null;
    }
}
