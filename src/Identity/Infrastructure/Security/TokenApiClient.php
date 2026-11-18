<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Security;

use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Shared\Application\Api\ApiClient;

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
