<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

/**
 * Mail text as a screen may show it: the text itself, with every web
 * address followed by its domain in brackets so the person sees where it leads,
 * and never a link. The result is still the sender's text: a template prints it
 * escaped, in a block that keeps its line breaks.
 */
final class UntrustedText
{
    /** What the text shows of a long address; the rest is cut so a line stays readable. */
    private const int MAX_ADDRESS = 200;

    private function __construct()
    {
    }

    public static function shown(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return (string) preg_replace_callback('~\b(?:https?|ftp)://[^\s<>"\']+~iu', static function (array $match): string {
            $address = rtrim($match[0], '.,;:!?)]}');
            $tail = substr($match[0], \strlen($address));
            $host = parse_url($address, \PHP_URL_HOST);
            $shown = mb_strlen($address) > self::MAX_ADDRESS ? mb_substr($address, 0, self::MAX_ADDRESS).'…' : $address;

            return $shown.' ['.(\is_string($host) && '' !== $host ? mb_strtolower($host) : '?').']'.$tail;
        }, $text);
    }
}
