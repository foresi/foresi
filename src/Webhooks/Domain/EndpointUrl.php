<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Domain;

/**
 * The address an endpoint is sent to: https, with a host, without user info and
 * at most 2048 characters. Only the form is checked here; whether the host may
 * be reached is the URL policy's to decide, which is the one place that makes
 * these.
 */
final readonly class EndpointUrl
{
    public const int MAX_LENGTH = 2048;

    public function __construct(public string $value)
    {
        $parts = parse_url($value);
        if (\strlen($value) > self::MAX_LENGTH
            || false === $parts
            || 'https' !== strtolower($parts['scheme'] ?? '')
            || '' === ($parts['host'] ?? '')
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new \InvalidArgumentException(\sprintf('A webhook endpoint URL is https, with a host, without user info and at most %d characters.', self::MAX_LENGTH));
        }
    }
}
