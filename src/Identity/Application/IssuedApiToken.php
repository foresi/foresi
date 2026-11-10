<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ApiToken;

/**
 * A new token and its secret, which is shown this once and kept nowhere.
 */
final readonly class IssuedApiToken
{
    public function __construct(
        public ApiToken $token,
        #[\SensitiveParameter] public string $secret,
    ) {
    }
}
