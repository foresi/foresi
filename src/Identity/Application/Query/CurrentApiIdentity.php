<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application\Query;

use App\Identity\Application\ApiTokenContext;
use App\Identity\Application\InsufficientScope;
use App\Identity\Application\MembershipDirectory;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\User;

/**
 * The person, organisation and token of the current token-signed request, for
 * a client to check what its token is.
 */
final readonly class CurrentApiIdentity
{
    public function __construct(
        private ApiTokenContext $tokens,
        private UserRepository $users,
        private MembershipDirectory $memberships,
    ) {
    }

    /**
     * @throws InsufficientScope without a token that reads
     */
    public function get(): ApiIdentity
    {
        $grant = $this->tokens->requireScope(ApiScope::Read);
        $user = $this->users->findById($grant->userId);
        if (!$user instanceof User) {
            throw new InsufficientScope(ApiScope::Read);
        }
        foreach ($this->memberships->organisationsOf($grant->userId) as $organisation) {
            if ($organisation->id->equals($grant->tenantId)) {
                return new ApiIdentity($user->id(), $user->email(), $organisation->id, $organisation->name, $grant);
            }
        }

        throw new InsufficientScope(ApiScope::Read);
    }
}
