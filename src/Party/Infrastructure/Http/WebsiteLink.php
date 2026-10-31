<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Http;

/**
 * The link a stored website gets on a page: an http or https URL as it is, a
 * bare domain ("example.com/page") with https:// in front. Anything else
 * (another scheme, spaces, a lone word) is no link and stays text.
 */
final class WebsiteLink
{
    private const string URL = '#^https?://[a-z0-9-]+(\.[a-z0-9-]+)*(:\d+)?([/?\#][^\s\\\\]*)?\z#i';
    private const string DOMAIN = '#^([a-z0-9-]+\.)+[a-z]{2,}([/?\#][^\s\\\\]*)?\z#i';

    public static function of(string $website): ?string
    {
        if (1 === preg_match(self::URL, $website)) {
            return $website;
        }

        return 1 === preg_match(self::DOMAIN, $website) ? 'https://'.$website : null;
    }
}
