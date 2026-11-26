<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\OAuth;

use Foresi\Identity\Domain\ApiToken;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Identity\Support\OAuthTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * OAuth 2.1 sign-in of an MCP client end to end (ADR 0036): metadata,
 * registration, the consent screen behind the sign-in, the token endpoint,
 * the access token on /mcp, rotation of the refresh token, and each refusal.
 */
final class OAuthFlowTest extends OAuthTestCase
{
    public function testTheMcpServerPointsToItsMetadata(): void
    {
        $this->client->request('POST', '/mcp');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"');

        $this->client->request('POST', '/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer foresi_oat_'.str_repeat('a', 43)]);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer error="invalid_token", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"');

        // The REST API has no OAuth.
        $this->client->request('GET', '/api/v1/me');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
    }

    public function testBothMetadataDocumentsDescribeThisInstallation(): void
    {
        foreach (['/.well-known/oauth-protected-resource/mcp', '/.well-known/oauth-protected-resource'] as $path) {
            $resource = $this->json('GET', $path);
            self::assertSame(self::MCP, $resource['resource']);
            self::assertSame(['http://localhost'], $resource['authorization_servers']);
            self::assertIsArray($resource['scopes_supported']);
            self::assertContains('read', $resource['scopes_supported']);
            self::assertContains('contacts:write', $resource['scopes_supported']);
            self::assertResponseHeaderSame('Access-Control-Allow-Origin', '*');
        }

        $server = $this->json('GET', '/.well-known/oauth-authorization-server');
        self::assertSame('http://localhost', $server['issuer']);
        self::assertSame('http://localhost/oauth/authorize', $server['authorization_endpoint']);
        self::assertSame('http://localhost/oauth/token', $server['token_endpoint']);
        self::assertSame('http://localhost/oauth/register', $server['registration_endpoint']);
        self::assertSame(['S256'], $server['code_challenge_methods_supported']);
        self::assertSame(['none'], $server['token_endpoint_auth_methods_supported']);
        self::assertTrue($server['client_id_metadata_document_supported']);
        self::assertTrue($server['authorization_response_iss_parameter_supported']);

        $this->client->request('OPTIONS', '/oauth/token');
        self::assertResponseStatusCodeSame(204);
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', '*');
    }

    public function testAClientConnectsRefreshesAndIsCutOffWhenARefreshTokenComesBack(): void
    {
        $clientId = $this->register();
        $this->signIn();

        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->query($clientId, ['scope' => 'read write', 'redirect_uri' => 'http://127.0.0.1:53682/callback']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Connect Example Agent');
        self::assertSelectorTextContains('main', 'cannot verify it');
        self::assertSelectorTextContains('main', 'you go back to 127.0.0.1');
        // Read access the client asked for stays ticked; writing can be unticked.
        self::assertNotNull($crawler->filter('#scope-read')->attr('disabled'));
        self::assertNotNull($crawler->filter('#scope-write')->attr('checked'));
        self::assertNull($crawler->filter('#scope-contacts-read')->attr('checked'));

        $form = $crawler->filter('button[value="allow"]')->form();
        $scopes = $form->get('scopes');
        $write = \is_array($scopes) ? $scopes[1] ?? null : null;
        self::assertInstanceOf(ChoiceFormField::class, $write);
        $write->untick();
        $this->client->submit($form);
        self::assertStringStartsWith('http://127.0.0.1:53682/callback?code=', $this->location());
        $answer = $this->answer();
        self::assertSame('xyz', $answer['state']);
        self::assertSame('http://localhost', $answer['iss']);

        $tokens = $this->token(['grant_type' => 'authorization_code', 'code' => $answer['code'], 'redirect_uri' => 'http://127.0.0.1:53682/callback', 'client_id' => $clientId, 'code_verifier' => self::VERIFIER, 'resource' => self::MCP]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertMatchesRegularExpression('/^foresi_oat_[A-Za-z0-9_-]{43}$/', $tokens['access_token']);
        self::assertStringStartsWith('foresi_ort_', $tokens['refresh_token']);
        self::assertSame('Bearer', $tokens['token_type']);
        self::assertSame('3600', $tokens['expires_in']);
        self::assertSame('read', $tokens['scope']);

        // One connection: an API token row of the client, not among the personal tokens.
        $connection = $this->connection();
        self::assertSame('Example Agent', $connection->name());
        self::assertSame(['read'], array_map(static fn (\Foresi\Identity\Domain\ApiScope $scope): string => $scope->value, $connection->scopes()));

        self::assertSame(200, $this->mcp($tokens['access_token']));
        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token']]);
        self::assertResponseStatusCodeSame(401);

        $renewed = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId]);
        self::assertResponseIsSuccessful();
        self::assertNotSame($tokens['refresh_token'], $renewed['refresh_token']);
        self::assertSame(401, $this->mcp($tokens['access_token']), 'The old access token still works.');
        self::assertSame(200, $this->mcp($renewed['access_token']));
        self::assertSame($connection->id(), $this->connection()->id());

        // The first refresh token again: stolen, so the whole connection ends.
        $this->assertTokenError('invalid_grant', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId]);
        self::assertSame(401, $this->mcp($renewed['access_token']));
        $this->assertTokenError('invalid_grant', ['grant_type' => 'refresh_token', 'refresh_token' => $renewed['refresh_token'], 'client_id' => $clientId]);
    }

    public function testACodeWorksOnceAndOnlyWithItsVerifierRedirectAndClient(): void
    {
        $clientId = $this->register();
        $other = $this->register();
        $this->signIn();
        $code = $this->approve($clientId);
        $exchange = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'client_id' => $clientId, 'code_verifier' => self::VERIFIER];

        $this->assertTokenError('invalid_grant', ['code_verifier' => 'another-verifier-that-is-long-enough-for-pkce-012345'] + $exchange);
        $this->assertTokenError('invalid_grant', ['redirect_uri' => 'http://127.0.0.1/other'] + $exchange);
        $this->assertTokenError('invalid_grant', ['client_id' => $other] + $exchange);
        $this->assertTokenError('invalid_target', ['resource' => 'https://elsewhere.example.com/mcp'] + $exchange);
        $this->assertTokenError('invalid_client', ['client_id' => 'foresi_client_unknown'] + $exchange, 401);
        $this->assertTokenError('unsupported_grant_type', ['grant_type' => 'password'] + $exchange);

        $tokens = $this->token($exchange);
        self::assertResponseIsSuccessful();

        // A second exchange of the same code revokes what the first one issued.
        $this->assertTokenError('invalid_grant', $exchange);
        self::assertSame(401, $this->mcp($tokens['access_token']));
    }

    public function testTheTokenEndpointTakesOnlyAForm(): void
    {
        $this->client->request('POST', '/oauth/token', server: ['CONTENT_TYPE' => 'application/json'], content: '{"grant_type":"authorization_code"}');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_request', $this->decoded()['error']);
    }

    public function testAnUntrustedClientOrRedirectIsShownAndNeverRedirectedTo(): void
    {
        $clientId = $this->register();
        $this->signIn();

        foreach ([
            ['client_id' => 'foresi_client_unknown'],
            ['redirect_uri' => 'https://attacker.example.com/callback'],
            ['redirect_uri' => 'http://127.0.0.1/other'],
        ] as $change) {
            $this->client->request('GET', '/oauth/authorize?'.$this->query($clientId, $change));
            self::assertResponseStatusCodeSame(400);
            self::assertSelectorTextContains('h1', 'The app cannot be connected');
        }
    }

    public function testOtherBadRequestsGoBackToTheClientWithAnError(): void
    {
        $clientId = $this->register();
        $this->signIn();

        foreach ([
            'invalid_target' => ['resource' => 'https://elsewhere.example.com/mcp'],
            'invalid_request' => ['code_challenge_method' => 'plain'],
            'unsupported_response_type' => ['response_type' => 'token'],
            'invalid_scope' => ['scope' => 'everything'],
        ] as $error => $change) {
            $this->client->request('GET', '/oauth/authorize?'.$this->query($clientId, $change));
            self::assertResponseRedirects();
            $answer = $this->answer();
            self::assertSame($error, $answer['error'] ?? null, $error);
            self::assertSame('xyz', $answer['state']);
        }

        // Without a resource at all.
        $this->client->request('GET', '/oauth/authorize?'.str_replace('&resource='.rawurlencode(self::MCP), '', $this->query($clientId)));
        self::assertStringContainsString('error=invalid_target', $this->location());
    }

    public function testDenyingSendsAccessDenied(): void
    {
        $clientId = $this->register();
        $this->signIn();

        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->query($clientId));
        $this->client->submit($crawler->filter('button[value="deny"]')->form());

        self::assertStringStartsWith(self::REDIRECT.'?error=access_denied', $this->location());
        self::assertSame([], $this->em->getRepository(ApiToken::class)->findAll());
    }

    public function testTheConsentScreenLetsItsFormEndOnlyAtTheClientsRedirect(): void
    {
        $clientId = $this->register();
        $this->signIn();

        // Chrome checks form-action on the redirect after the submit, so the page names the client's origin.
        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->query($clientId, ['redirect_uri' => 'http://127.0.0.1:53682/callback']));
        self::assertResponseIsSuccessful();
        self::assertSame(["'self'", 'http://127.0.0.1:53682'], $this->formAction());

        $this->client->submit($crawler->filter('button[value="deny"]')->form());
        self::assertStringStartsWith('http://127.0.0.1:53682/callback?error=access_denied', $this->location());

        // Allowing goes to the same origin, and the next page posts only to Foresi again.
        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->query($clientId));
        self::assertSame(["'self'", 'http://127.0.0.1'], $this->formAction());
        $this->client->submit($crawler->filter('button[value="allow"]')->form());
        self::assertStringStartsWith(self::REDIRECT.'?code=', $this->location());
        $this->client->request('GET', '/connected-apps');
        self::assertSame(["'self'"], $this->formAction());

        // A redirect the client never registered gets an error page, and no form may leave.
        $this->client->request('GET', '/oauth/authorize?'.$this->query($clientId, ['redirect_uri' => 'https://elsewhere.example.com/callback']));
        self::assertResponseStatusCodeSame(400);
        self::assertSame(["'self'"], $this->formAction());
    }

    public function testTheConsentScreenGroupsTheAccessAndNamesWhoSignsIn(): void
    {
        $clientId = $this->register();
        $this->signIn();

        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->query($clientId, ['scope' => 'read projects:write']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Not verified');
        self::assertSelectorTextContains('main', 'Signed in as');
        self::assertSelectorTextContains('main', IdentityFixtures::EMAIL);
        // The whole account first, then each module with its resources, each with reading and changing.
        self::assertSelectorTextContains('[role="group"][aria-labelledby="access-all-label"]', 'All data');
        $work = $crawler->filterXPath('//*[@role="group"][p[normalize-space()="Work"]]');
        self::assertCount(1, $work);
        self::assertCount(1, $work->filter('#scope-projects-read'));
        self::assertNotNull($work->filter('#scope-projects-write')->attr('checked'));
        self::assertNull($work->filter('#scope-tasks-write')->attr('checked'));
        // A single-resource module is one row, without a heading of its own.
        self::assertCount(1, $crawler->filterXPath('//*[@role="group"][span[normalize-space()="Contacts"]]'));
        // The client asked for one resource, so the areas are shown open.
        self::assertNotNull($crawler->filter('details')->attr('open'));

        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->query($clientId, ['scope' => 'read']));
        self::assertNull($crawler->filter('details')->attr('open'));
    }

    public function testTheConsentScreenNeedsASignInAndTwoFactorSignIn(): void
    {
        $clientId = $this->register();

        $this->client->request('GET', '/oauth/authorize?'.$this->query($clientId));
        self::assertResponseRedirects('/login');

        $this->signIn(twoFactor: false);
        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->query($clientId));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'only be connected with two-factor sign-in');
        self::assertCount(0, $crawler->filter('button[value="allow"]'));
    }

    public function testRegistrationTakesOnlyPublicClientsWithSafeRedirects(): void
    {
        foreach ([
            'invalid_redirect_uri' => ['redirect_uris' => ['http://example.com/callback']],
            'invalid_client_metadata' => ['redirect_uris' => [self::REDIRECT], 'token_endpoint_auth_method' => 'client_secret_basic'],
        ] as $error => $metadata) {
            $this->client->request('POST', '/oauth/register', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($metadata));
            self::assertResponseStatusCodeSame(400);
            self::assertSame($error, $this->decoded()['error']);
        }
        $this->client->request('POST', '/oauth/register', server: ['CONTENT_TYPE' => 'application/json'], content: '[1]');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', '/oauth/register', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['redirect_uris' => ['cursor://anysphere.cursor-mcp/oauth/callback'], 'client_name' => 'Cursor']));
        self::assertResponseStatusCodeSame(201);
        $registered = $this->decoded();
        self::assertIsString($registered['client_id']);
        self::assertStringStartsWith('foresi_client_', $registered['client_id']);
        self::assertSame('none', $registered['token_endpoint_auth_method']);
        self::assertArrayNotHasKey('client_secret', $registered);
    }
}
