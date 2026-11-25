<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

/**
 * The domains of free mail providers, where anyone can have an address: a
 * sender's domain there says nothing about the company they work for, so an
 * item is never matched by such a domain. The list is shipped with the code and
 * kept to well-known providers; a provider missing from it only means a domain
 * match is tried, which still needs exactly one company with that domain.
 */
final class FreeMailDomains
{
    /** Domains taken exactly. */
    public const array DOMAINS = [
        // Czech Republic and Slovakia
        'atlas.cz', 'azet.sk', 'centrum.cz', 'centrum.sk', 'email.cz', 'post.cz', 'quick.cz', 'seznam.cz',
        'spoluzaci.cz', 'tiscali.cz', 'volny.cz', 'zoznam.sk',
        // Google, Microsoft, Apple, Yahoo
        'gmail.com', 'googlemail.com',
        'hotmail.com', 'hotmail.cz', 'hotmail.co.uk', 'hotmail.de', 'live.com', 'live.cz', 'msn.com', 'outlook.com', 'outlook.cz',
        'icloud.com', 'mac.com', 'me.com',
        'yahoo.com', 'yahoo.co.uk', 'yahoo.de', 'ymail.com',
        // Others
        'aol.com', 'fastmail.com', 'hey.com', 'mail.com', 'mail.ru', 'pm.me', 'proton.me', 'protonmail.ch', 'protonmail.com',
        't-online.de', 'tuta.io', 'tutanota.com', 'web.de', 'yandex.com', 'yandex.ru', 'zoho.com',
    ];

    /** Providers taken under any top-level domain, such as gmx.com, gmx.net or gmx.de. */
    public const array ANY_TOP_LEVEL = ['gmx'];

    /**
     * @param string $domain such as example.com, without regard to case, with or without a leading "@"
     */
    public static function isFree(string $domain): bool
    {
        // Domain names are ASCII here (an internationalised one arrives as punycode), so no multibyte case folding.
        $domain = rtrim(ltrim(strtolower(trim($domain)), '@'), '.');
        if (\in_array($domain, self::DOMAINS, true)) {
            return true;
        }
        $dot = strpos($domain, '.');

        return false !== $dot
            && \in_array(substr($domain, 0, $dot), self::ANY_TOP_LEVEL, true)
            && 1 === preg_match('/^[a-z]{2,3}(\.[a-z]{2})?$/', substr($domain, $dot + 1));
    }
}
