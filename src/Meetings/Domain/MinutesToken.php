<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Domain;

use Foresi\Shared\Application\TaskReferenceSyntax;

/**
 * Where the token of a new action item goes in the minutes, when an agent makes
 * it: in place of the first exact occurrence of a quote outside another token,
 * or as a new list item at the end.
 */
final class MinutesToken
{
    private function __construct()
    {
    }

    /**
     * @return string|null the minutes with the token in place of the quote; null when the quote is not there
     */
    public static function inPlaceOf(string $minutes, string $quote, string $taskId): ?string
    {
        if ('' === trim($quote) || 1 === preg_match('/[\r\n]/', $quote)) {
            return null;
        }
        preg_match_all('/'.TaskReferenceSyntax::PATTERN.'/u', $minutes, $tokens, \PREG_OFFSET_CAPTURE);
        $offset = 0;
        while (false !== ($at = strpos($minutes, $quote, $offset))) {
            $end = $at + \strlen($quote);
            if (!array_any($tokens[0], static fn (array $found): bool => $at < $found[1] + \strlen($found[0]) && $end > $found[1])) {
                return substr_replace($minutes, TaskReferenceSyntax::token($quote, $taskId), $at, \strlen($quote));
            }
            $offset = $at + 1;
        }

        return null;
    }

    /**
     * The minutes with the token as a new list item at the end: in the list the minutes end with, else after a blank line.
     */
    public static function appended(string $minutes, string $title, string $taskId): string
    {
        $item = '- '.TaskReferenceSyntax::token($title, $taskId);
        $minutes = rtrim($minutes);
        if ('' === $minutes) {
            return $item."\n";
        }
        $last = substr($minutes, (int) strrpos($minutes, "\n"));

        return $minutes.(1 === preg_match('/\A\s*[-*+] /', $last) ? "\n" : "\n\n").$item."\n";
    }
}
