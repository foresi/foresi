<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Domain\OAuth;

/**
 * The rules for where an OAuth client may send a person back (ADR 0036): an
 * https URL, a loopback http URL of a native client, or a private-use scheme
 * such as cursor://. A redirect matches the registered one exactly, except that
 * a loopback one matches on any port (RFC 8252, 7.3), since a native client
 * picks a free port when it starts.
 */
final class RedirectUri
{
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /** Schemes that run code or read files instead of reaching a client. */
    private const array REFUSED_SCHEMES = ['javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'filesystem', 'ftp', 'ws', 'wss'];

    public const int MAX_LENGTH = 2000;

    public static function isAllowed(string $uri): bool
    {
        if ('' === $uri || \strlen($uri) > self::MAX_LENGTH || 1 === preg_match('/[\x00-\x20\x7f]/', $uri) || str_contains($uri, '#')) {
            return false;
        }
        $parts = parse_url($uri);
        if (false === $parts || !isset($parts['scheme']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ('https' === $scheme) {
            return isset($parts['host']) && '' !== $parts['host'];
        }
        if ('http' === $scheme) {
            return self::isLoopback($uri);
        }

        // parse_url() already keeps a scheme to letters, digits, "+", "-" and ".".
        return 1 === preg_match('/^[a-z]/', $scheme) && !\in_array($scheme, self::REFUSED_SCHEMES, true);
    }

    public static function matches(string $registered, string $requested): bool
    {
        if ($registered === $requested) {
            return true;
        }
        if (!self::isLoopback($registered) || !self::isLoopback($requested)) {
            return false;
        }

        return self::withoutPort($registered) === self::withoutPort($requested);
    }

    private static function isLoopback(string $uri): bool
    {
        $parts = parse_url($uri);

        return false !== $parts
            && 'http' === strtolower($parts['scheme'] ?? '')
            && \in_array(strtolower($parts['host'] ?? ''), self::LOOPBACK_HOSTS, true)
            && !isset($parts['user']);
    }

    /**
     * Where a person is sent back, as the consent screen shows it: the host, or
     * the scheme of a private-use redirect.
     */
    public static function displayHost(string $uri): string
    {
        $parts = parse_url($uri);
        if (false === $parts) {
            return $uri;
        }
        $scheme = strtolower($parts['scheme'] ?? '');

        return \in_array($scheme, ['http', 'https'], true) ? strtolower($parts['host'] ?? '') : $scheme.'://'.($parts['host'] ?? '');
    }

    private static function withoutPort(string $uri): string
    {
        return (string) preg_replace('#^(http://(?:localhost|127\.0\.0\.1|\[::1\])):\d+#i', '$1', $uri);
    }
}
