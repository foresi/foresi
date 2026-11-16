<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Support\Mail;

/**
 * Resolves host names for the mail host policy and the webhook URL policy in
 * the test and end-to-end environments, so no test asks a real DNS server.
 * Names under example.com get a public address, internal.example.com a private
 * one, and anything else none.
 */
final class ExampleHostResolver
{
    /**
     * @return list<string>
     */
    public function __invoke(string $host): array
    {
        return match (true) {
            'internal.example.com' === $host => ['10.0.0.1'],
            'example.com' === $host, str_ends_with($host, '.example.com') => ['93.184.215.14'],
            default => [],
        };
    }
}
