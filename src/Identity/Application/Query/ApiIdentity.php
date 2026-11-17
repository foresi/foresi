<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application\Query;

use App\Identity\Application\ApiTokenGrant;

/**
 * Who a token-signed request acts as: the person and the token.
 */
final readonly class ApiIdentity
{
    public function __construct(
        public string $userId,
        public string $email,
        public ApiTokenGrant $token,
    ) {
    }
}
