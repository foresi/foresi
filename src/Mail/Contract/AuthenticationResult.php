<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * One Authentication-Results header of a message, as parsed. The authserv-id
 * may be written by anyone, unless a trusted receiving server wrote the header.
 */
final readonly class AuthenticationResult
{
    /**
     * @param string                                                                  $authservId lowercase, without a trailing dot
     * @param bool                                                                    $trusted    whether the authserv-id is a receiving server trusted for the channel the message came by
     * @param list<array{method: string, result: string, props: array<string, string>}> $results
     */
    public function __construct(
        public string $authservId,
        public bool $trusted,
        public array $results,
    ) {
    }
}
