<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application\Query;

use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\User;

/**
 * Who a token-signed request acts as: the person and the token.
 */
final readonly class ApiIdentity
{
    public function __construct(
        public string $userId,
        public string $email,
        public ApiTokenGrant $token,
        public User $user,
    ) {
    }
}
