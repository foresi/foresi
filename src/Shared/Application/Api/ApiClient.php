<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application\Api;

/**
 * The personal API token the request signed in with, as far as the shared
 * plumbing of the API needs it: its id, which the rate limit is counted by.
 */
interface ApiClient
{
    /**
     * @return string|null null when the request did not sign in with a token
     */
    public function tokenId(): ?string;
}
