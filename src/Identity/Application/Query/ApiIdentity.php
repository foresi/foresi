<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application\Query;

use App\Identity\Application\ApiTokenGrant;
use App\Tenancy\Domain\TenantId;

/**
 * Who a token-signed request acts as: the person, the organisation and the token.
 */
final readonly class ApiIdentity
{
    public function __construct(
        public string $userId,
        public string $email,
        public TenantId $tenantId,
        public string $tenantName,
        public ApiTokenGrant $token,
    ) {
    }
}
