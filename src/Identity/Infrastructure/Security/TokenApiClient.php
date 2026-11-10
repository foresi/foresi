<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\ApiTokenContext;
use App\Shared\Application\Api\ApiClient;

/**
 * The client of the shared contract, answered by the personal API token.
 */
final readonly class TokenApiClient implements ApiClient
{
    public function __construct(private ApiTokenContext $context)
    {
    }

    public function tokenId(): ?string
    {
        return $this->context->grant()?->tokenId;
    }
}
