<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\ApiTokenRefused;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * A presented token that signs nobody in, with the problem code the client
 * gets. An unknown token, a guest's and one of a person who left all answer
 * the same, so the answer tells nothing about who has tokens.
 */
final class ApiTokenRejected extends AuthenticationException
{
    public const string INVALID = 'invalid_token';
    public const string EXPIRED = 'token_expired';
    public const string REVOKED = 'token_revoked';

    private string $problem = self::INVALID;

    public static function because(string $reason): self
    {
        $rejected = new self('The API token was rejected.');
        $rejected->problem = match ($reason) {
            ApiTokenRefused::EXPIRED => self::EXPIRED,
            ApiTokenRefused::REVOKED => self::REVOKED,
            default => self::INVALID,
        };

        return $rejected;
    }

    public function problem(): string
    {
        return $this->problem;
    }

    public function getMessageKey(): string
    {
        return 'Invalid credentials.';
    }
}
