<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application\OAuth;

/**
 * An OAuth request Foresi turns down, with the error code of RFC 6749 or RFC
 * 7591. One with a redirect URI goes back to the client there; one without,
 * because the client or the URI is not trusted, is shown to the person.
 */
final class OAuthRefused extends \RuntimeException
{
    public const string INVALID_REQUEST = 'invalid_request';
    public const string INVALID_CLIENT = 'invalid_client';
    public const string INVALID_GRANT = 'invalid_grant';
    public const string INVALID_SCOPE = 'invalid_scope';
    public const string INVALID_TARGET = 'invalid_target';
    public const string UNSUPPORTED_GRANT_TYPE = 'unsupported_grant_type';
    public const string UNSUPPORTED_RESPONSE_TYPE = 'unsupported_response_type';
    public const string ACCESS_DENIED = 'access_denied';
    public const string INVALID_REDIRECT_URI = 'invalid_redirect_uri';
    public const string INVALID_CLIENT_METADATA = 'invalid_client_metadata';

    public function __construct(
        public readonly string $error,
        public readonly string $description,
        public readonly ?string $redirectUri = null,
        public readonly ?string $state = null,
    ) {
        parent::__construct($description);
    }

    /**
     * The same refusal, to be sent to this redirect URI of the client.
     */
    public function at(string $redirectUri, ?string $state): self
    {
        return new self($this->error, $this->description, $redirectUri, $state);
    }

    public static function toPerson(string $error, string $description): self
    {
        return new self($error, $description);
    }
}
