<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * Reads an Authentication-Results header (RFC 8601) and the tag lists of the ARC
 * headers (RFC 8617). Comments are dropped wherever they stand, a quoted string is
 * one value even when it holds a semicolon, and names and results are lowercased;
 * property values stay as written. What cannot be read is left out rather than
 * guessed, so a broken header only ever says less.
 */
final class AuthenticationResultsParser
{
    /**
     * A name, a property name with its dot (CFWS around the dot allowed), or a
     * method with its version, then "=" and a value. A quoted string is skipped
     * whole, so nothing inside it reads as a result; any other run is skipped too.
     */
    private const string PAIRS = '/(?<key>[A-Za-z0-9_-]+(?:\s*[.\/]\s*[A-Za-z0-9_-]+)*)\s*=\s*(?<value>"(?:[^"\\\\]|\\\\.)*"?|[^\s";]*)|"(?:[^"\\\\]|\\\\.)*"?|[^\s"]+/s';

    /**
     * @return array{authserv: string, results: list<array{method: string, result: string, props: array<string, string>}>}
     */
    public function parse(string $header): array
    {
        $segments = $this->segments($header);
        $results = [];
        foreach (\array_slice($segments, 1) as $segment) {
            $result = $this->result($segment);
            if (null !== $result) {
                $results[] = $result;
            }
        }

        return ['authserv' => $this->authserv($segments[0]), 'results' => $results];
    }

    /**
     * The tags of an ARC-Seal, an ARC-Message-Signature or a DKIM-Signature, by
     * lowercased name; the first of a repeated tag counts.
     *
     * @return array<string, string>
     */
    public function tags(string $header): array
    {
        $tags = [];
        foreach (explode(';', $header) as $part) {
            $equals = strpos($part, '=');
            if (false === $equals) {
                continue;
            }
            $name = strtolower(trim(substr($part, 0, $equals)));
            if ('' !== $name && !\array_key_exists($name, $tags)) {
                $tags[$name] = trim(substr($part, $equals + 1));
            }
        }

        return $tags;
    }

    /**
     * The value with its comments and quoted strings taken out, for counting what
     * it really holds: an at sign in a display name or a comment is no address.
     */
    public function bare(string $value): string
    {
        return implode(';', $this->segments($value, keepQuoted: false));
    }

    /**
     * Splits on the semicolons outside quoted strings and drops every comment.
     *
     * @return non-empty-list<string>
     */
    private function segments(string $value, bool $keepQuoted = true): array
    {
        $segments = [];
        $current = '';
        $depth = 0;
        $quoted = false;
        $length = \strlen($value);
        for ($at = 0; $at < $length; ++$at) {
            $char = $value[$at];
            if ($depth > 0) {
                if ('\\' === $char) {
                    ++$at;
                } elseif ('(' === $char) {
                    ++$depth;
                } elseif (')' === $char) {
                    --$depth;
                }
                continue;
            }
            if ($quoted) {
                if ('\\' === $char && $at + 1 < $length) {
                    ++$at;
                    $char .= $value[$at];
                } elseif ('"' === $char) {
                    $quoted = false;
                }
                if ($keepQuoted) {
                    $current .= $char;
                }
                continue;
            }
            if ('(' === $char) {
                $depth = 1;
                $current .= ' ';
            } elseif ('"' === $char) {
                $quoted = true;
                $current .= $keepQuoted ? $char : '';
            } elseif (';' === $char) {
                $segments[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        $segments[] = $current;

        return $segments;
    }

    private function authserv(string $segment): string
    {
        $segment = trim($segment);
        if (str_starts_with($segment, '"')) {
            return strtolower($this->unquote($segment));
        }

        return strtolower(preg_split('/\s+/', $segment)[0] ?? '');
    }

    /**
     * @return array{method: string, result: string, props: array<string, string>}|null
     */
    private function result(string $segment): ?array
    {
        preg_match_all(self::PAIRS, $segment, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);
        $method = null;
        $result = '';
        $props = [];
        foreach ($matches as $match) {
            if (null === $match['key'] || null === $match['value']) {
                continue;
            }
            $key = strtolower(preg_replace('/\s+/', '', $match['key']) ?? '');
            $value = str_starts_with($match['value'], '"') ? $this->unquote($match['value']) : $match['value'];
            if (null === $method) {
                if (str_contains($key, '.')) {
                    return null;
                }
                $method = explode('/', $key)[0];
                $result = strtolower($value);
                continue;
            }
            if ('' !== $value && ('reason' === $key || str_contains($key, '.')) && !\array_key_exists($key, $props)) {
                $props[$key] = $value;
            }
        }

        return null === $method || '' === $result ? null : ['method' => $method, 'result' => $result, 'props' => $props];
    }

    /**
     * The content of a quoted string that starts the value, unescaped; an
     * unclosed one runs to the end.
     */
    private function unquote(string $quoted): string
    {
        $content = '';
        $length = \strlen($quoted);
        for ($at = 1; $at < $length; ++$at) {
            $char = $quoted[$at];
            if ('"' === $char) {
                break;
            }
            if ('\\' === $char && $at + 1 < $length) {
                $char = $quoted[++$at];
            }
            $content .= $char;
        }

        return $content;
    }
}
