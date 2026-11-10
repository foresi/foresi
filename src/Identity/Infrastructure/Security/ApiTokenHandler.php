<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\ApiTokenAuthentication;
use App\Identity\Application\ApiTokenRefused;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\User;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Signs a request on the api and mcp firewalls in with the bearer token. The
 * grant travels in the badge to the security token (ApiTokenGrantOnToken).
 */
final readonly class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private ApiTokenAuthentication $authentication,
        private UserRepository $users,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        try {
            $grant = $this->authentication->authenticate($accessToken);
        } catch (ApiTokenRefused $refused) {
            throw ApiTokenRejected::because($refused->reason);
        }
        $user = $this->users->findById($grant->userId);
        if (!$user instanceof User) {
            throw ApiTokenRejected::because(ApiTokenRefused::MEMBERSHIP_GONE);
        }

        return new UserBadge($user->email(), null, [SecurityApiTokenContext::ATTRIBUTE => $grant]);
    }
}
