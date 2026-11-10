<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * A personal API token was not issued, listed, revoked or accepted. The reason
 * is one of the constants, a stable name each surface turns into its answer.
 */
final class ApiTokenRefused extends \DomainException
{
    /** No token has this secret, or the secret is not one of ours. */
    public const string UNKNOWN = 'unknown';
    public const string EXPIRED = 'expired';
    public const string REVOKED = 'revoked';
    /** The person no longer belongs to the organisation of the token. */
    public const string MEMBERSHIP_GONE = 'membership_gone';
    /** Guests see only what is shared with them, never through a token. */
    public const string GUEST = 'guest';
    /** Only the owner and the members of the organisation have tokens. */
    public const string NOT_STAFF = 'not_staff';
    /** A token is issued only to someone with two-factor sign-in set up. */
    public const string TWO_FACTOR_REQUIRED = 'two_factor_required';
    /** Someone else's token, which only an owner revokes or lists. */
    public const string NOT_YOURS = 'not_yours';
    public const string NAME = 'name';
    public const string SCOPES = 'scopes';
    public const string EXPIRY = 'expiry';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(\sprintf('The API token was refused: %s.', $reason));
    }
}
