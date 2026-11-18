<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Identity\Domain\ApiTokenSecret;
use Foresi\Identity\Domain\ApiTokenState;
use Foresi\Identity\Domain\Role;
use Psr\Clock\ClockInterface;

/**
 * Turns the secret a request presents into what it may do. The token must be
 * known, neither revoked nor expired, and its person must still work for the
 * organisation; guests never sign in with a token. The time of last use is
 * recorded at most once a minute, so a busy client does not write on every call.
 */
final readonly class ApiTokenAuthentication
{
    /** Seconds within which a second use is not recorded. */
    public const int LAST_USED_PRECISION = 60;

    public function __construct(
        private ApiTokens $tokens,
        private MembershipDirectory $memberships,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ApiTokenRefused
     */
    public function authenticate(#[\SensitiveParameter] string $secret): ApiTokenGrant
    {
        if (!ApiTokenSecret::isWellFormed($secret)) {
            throw new ApiTokenRefused(ApiTokenRefused::UNKNOWN);
        }
        $record = $this->tokens->byHash(ApiTokenSecret::hash($secret)) ?? throw new ApiTokenRefused(ApiTokenRefused::UNKNOWN);

        $now = $this->clock->now();
        $state = $record->state($now);
        if (ApiTokenState::Revoked === $state) {
            throw new ApiTokenRefused(ApiTokenRefused::REVOKED);
        }
        if (ApiTokenState::Expired === $state) {
            throw new ApiTokenRefused(ApiTokenRefused::EXPIRED);
        }

        $role = $this->memberships->roleOf($record->userId);
        if (!$role instanceof Role) {
            throw new ApiTokenRefused(ApiTokenRefused::MEMBERSHIP_GONE);
        }
        if (!$role->isStaff()) {
            throw new ApiTokenRefused(ApiTokenRefused::GUEST);
        }

        $usedAfter = $now->modify(\sprintf('-%d seconds', self::LAST_USED_PRECISION));
        if (!$record->lastUsedAt instanceof \DateTimeImmutable || $record->lastUsedAt <= $usedAfter) {
            $this->tokens->touch($record->id, $now, $usedAfter);
        }

        return new ApiTokenGrant($record->userId, $record->id, $record->name, $record->scopes, $record->expiresAt);
    }
}
