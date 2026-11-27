<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\OAuth;

use Foresi\Tests\Identity\Support\OAuthTestCase;
use Foresi\Ui\UiBundle;

/**
 * The authorization server's metadata with and without the consent screen,
 * which lives with the screens (Identity/Integration/Ui, ADR 0038): without Ui
 * nobody can sign in in a browser, so the metadata names no authorization
 * endpoint and offers no authorization code.
 */
final class OAuthServerMetadataTest extends OAuthTestCase
{
    public function testTheMetadataOffersTheAuthorizationCodeOnlyWithTheConsentScreen(): void
    {
        $server = $this->json('GET', '/.well-known/oauth-authorization-server');

        self::assertSame('http://localhost', $server['issuer']);
        self::assertSame('http://localhost/oauth/token', $server['token_endpoint']);
        self::assertSame('http://localhost/oauth/register', $server['registration_endpoint']);
        if (class_exists(UiBundle::class)) {
            self::assertSame('http://localhost/oauth/authorize', $server['authorization_endpoint']);
            self::assertSame(['authorization_code', 'refresh_token'], $server['grant_types_supported']);
        } else {
            self::assertArrayNotHasKey('authorization_endpoint', $server);
            self::assertSame(['refresh_token'], $server['grant_types_supported']);
        }
    }
}
