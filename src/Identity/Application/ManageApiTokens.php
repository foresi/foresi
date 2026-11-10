<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenSecret;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Tenancy\Application\TenantContext;
use Psr\Clock\ClockInterface;

/**
 * Personal API tokens of the active organisation: the owner and the members
 * issue, list and revoke their own; an owner may also list and revoke anyone's.
 * Guests have none. Issuing needs two-factor sign-in, except for the operator
 * on the console, who issues for a person and revokes any token.
 */
final readonly class ManageApiTokens
{
    /** The lifetimes offered, in days. */
    public const array EXPIRY_DAYS = [30, 90, 365];
    public const int DEFAULT_EXPIRY_DAYS = 90;

    public function __construct(
        private ApiTokens $tokens,
        private UserRepository $users,
        private MembershipDirectory $memberships,
        private TenantContext $context,
        private AuditActor $actor,
        private ClockInterface $clock,
    ) {
    }

    /**
     * A token of the signed-in person.
     *
     * @param list<ApiScope> $scopes
     *
     * @throws ApiTokenRefused
     */
    public function issue(string $name, array $scopes = [ApiScope::Read], int $days = self::DEFAULT_EXPIRY_DAYS): IssuedApiToken
    {
        $userId = $this->staff();
        $user = $this->users->findById($userId);
        if (!$user instanceof User || !$user->hasTwoFactor()) {
            throw new ApiTokenRefused(ApiTokenRefused::TWO_FACTOR_REQUIRED);
        }

        return $this->create($userId, $name, $scopes, $days);
    }

    /**
     * A token the operator issues for a person of the active organisation.
     *
     * @param list<ApiScope> $scopes
     *
     * @throws ApiTokenRefused
     */
    public function issueAsOperator(string $userId, string $name, array $scopes = [ApiScope::Read], int $days = self::DEFAULT_EXPIRY_DAYS): IssuedApiToken
    {
        $role = $this->roleOf($userId);
        if (!$role instanceof Role || !$role->isStaff()) {
            throw new ApiTokenRefused(ApiTokenRefused::NOT_STAFF);
        }

        return $this->create($userId, $name, $scopes, $days);
    }

    /**
     * @return list<ApiToken> the signed-in person's tokens, newest first
     *
     * @throws ApiTokenRefused
     */
    public function mine(): array
    {
        return $this->tokens->ofUser($this->staff());
    }

    /**
     * @return list<ApiToken> every token of the organisation, newest first; for an owner
     *
     * @throws ApiTokenRefused
     */
    public function organisation(): array
    {
        if (Role::Owner !== $this->roleOf($this->staff())) {
            throw new ApiTokenRefused(ApiTokenRefused::NOT_YOURS);
        }

        return $this->tokens->all();
    }

    /**
     * Revokes one's own token, or any token of the organisation as its owner.
     *
     * @throws ApiTokenRefused
     */
    public function revoke(string $id): void
    {
        $userId = $this->staff();
        $token = $this->tokens->find($id) ?? throw new ApiTokenRefused(ApiTokenRefused::UNKNOWN);
        if ($token->userId() !== $userId && Role::Owner !== $this->roleOf($userId)) {
            throw new ApiTokenRefused(ApiTokenRefused::NOT_YOURS);
        }
        $token->revoke($userId, $this->clock->now());
        $this->tokens->flush();
    }

    /**
     * The operator revokes any token of the active organisation.
     *
     * @throws ApiTokenRefused
     */
    public function revokeAsOperator(string $id): ApiToken
    {
        $token = $this->tokens->find($id) ?? throw new ApiTokenRefused(ApiTokenRefused::UNKNOWN);
        $token->revoke(null, $this->clock->now());
        $this->tokens->flush();

        return $token;
    }

    /**
     * @param list<ApiScope> $scopes
     */
    private function create(string $userId, string $name, array $scopes, int $days): IssuedApiToken
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > ApiToken::NAME_MAX_LENGTH) {
            throw new ApiTokenRefused(ApiTokenRefused::NAME);
        }
        if ([] === $scopes) {
            throw new ApiTokenRefused(ApiTokenRefused::SCOPES);
        }
        if (!\in_array($days, self::EXPIRY_DAYS, true)) {
            throw new ApiTokenRefused(ApiTokenRefused::EXPIRY);
        }

        $now = $this->clock->now();
        $secret = ApiTokenSecret::generate();
        $token = new ApiToken($userId, $name, $scopes, ApiTokenSecret::hash($secret), ApiTokenSecret::lastFour($secret), $now, $now->modify(\sprintf('+%d days', $days)));
        $this->tokens->add($token);
        $this->tokens->flush();

        return new IssuedApiToken($token, $secret);
    }

    /**
     * @return string the id of the signed-in person, who works for the organisation
     */
    private function staff(): string
    {
        $userId = $this->actor->userId();
        $role = null === $userId ? null : $this->roleOf($userId);
        if (null === $userId || !$role instanceof Role || !$role->isStaff()) {
            throw new ApiTokenRefused(ApiTokenRefused::NOT_STAFF);
        }

        return $userId;
    }

    private function roleOf(string $userId): ?Role
    {
        if (!$this->context->has()) {
            return null;
        }
        $tenant = $this->context->current();
        foreach ($this->memberships->organisationsOf($userId) as $organisation) {
            if ($organisation->id->equals($tenant)) {
                return $organisation->role;
            }
        }

        return null;
    }
}
