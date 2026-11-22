<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Infrastructure;

use Foresi\Calendar\Contract\CalendarUids;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Uids on the host of the installation's public address, DEFAULT_URI, which a
 * feed has whichever host its request came on.
 */
final readonly class DefaultUriCalendarUids implements CalendarUids
{
    private string $host;

    public function __construct(#[Autowire(env: 'DEFAULT_URI')] string $defaultUri)
    {
        $host = parse_url($defaultUri, \PHP_URL_HOST);
        $this->host = \is_string($host) && '' !== $host ? strtolower($host) : 'localhost';
    }

    public function of(string $key, string $id): string
    {
        return $key.'-'.$id.'@'.$this->host;
    }
}
