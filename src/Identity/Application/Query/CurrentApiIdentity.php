<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application\Query;

use App\Identity\Application\ApiTokenContext;
use App\Identity\Application\InsufficientScope;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\User;

/**
 * The person and token of the current token-signed request, for
 * a client to check what its token is.
 */
final readonly class CurrentApiIdentity
{
    public function __construct(
        private ApiTokenContext $tokens,
        private UserRepository $users,
    ) {
    }

    /**
     * Any scope will do: every token may tell what it is.
     *
     * @throws InsufficientScope without a token
     */
    public function get(): ApiIdentity
    {
        $grant = $this->tokens->grant() ?? throw new InsufficientScope(ApiScope::read());
        $user = $this->users->findById($grant->userId);
        if (!$user instanceof User) {
            throw new InsufficientScope(ApiScope::read());
        }

        return new ApiIdentity($user->id(), $user->email(), $grant);
    }
}
