<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\OAuth;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\ApiToken;
use Foresi\Identity\Domain\OAuth\Pkce;
use Foresi\Identity\Domain\User;
use Foresi\Shared\Application\Sealer;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * OAuth 2.1 sign-in of an MCP client end to end (ADR 0036): metadata,
 * registration, the consent screen behind the sign-in, the token endpoint,
 * the access token on /mcp, rotation of the refresh token, and each refusal.
 */
final class OAuthFlowTest extends WebTestCase
{
    private const string MCP = 'http://localhost/mcp';
    private const string REDIRECT = 'http://127.0.0.1/callback';
    private const string VERIFIER = 'a-verifier-that-is-long-enough-for-pkce-0123456789';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $fixtures = new IdentityFixtures($this->em);
        $fixtures->rebuildSchema();
        $fixtures->join($fixtures->user());
    }

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

    private function register(): string
    {
        $this->client->request('POST', '/oauth/register', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['client_name' => 'Example Agent', 'redirect_uris' => [self::REDIRECT]]));
        self::assertResponseStatusCodeSame(201);
        $clientId = $this->decoded()['client_id'];
        self::assertIsString($clientId);

        return $clientId;
    }

    private function approve(string $clientId): string
    {
        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->query($clientId));
        $this->client->submit($crawler->filter('button[value="allow"]')->form());

        return $this->answer()['code'] ?? '';
    }

    private function location(): string
    {
        return (string) $this->client->getResponse()->headers->get('Location');
    }

    /**
     * @return array<string, string> the query of the redirect back to the client
     */
    private function answer(): array
    {
        parse_str((string) parse_url($this->location(), \PHP_URL_QUERY), $query);
        $answer = [];
        foreach ($query as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $answer[$name] = $value;
            }
        }

        return $answer;
    }

    /**
     * @param array<string, string> $change
     */
    private function query(string $clientId, array $change = []): string
    {
        return http_build_query($change + [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'state' => 'xyz',
            'code_challenge' => Pkce::challenge(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'resource' => self::MCP,
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array<string, string> the answer, numbers as text
     */
    private function token(array $parameters): array
    {
        $this->client->request('POST', '/oauth/token', $parameters);
        $answer = [];
        foreach ($this->decoded() as $name => $value) {
            if (\is_string($value) || \is_int($value)) {
                $answer[$name] = (string) $value;
            }
        }

        return $answer;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function assertTokenError(string $error, array $parameters, int $status = 400): void
    {
        $answer = $this->token($parameters);
        self::assertResponseStatusCodeSame($status);
        self::assertSame($error, $answer['error'] ?? null, $answer['error_description'] ?? '');
    }

    private function mcp(string $accessToken): int
    {
        $this->client->request('POST', '/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');

        return \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED === $this->client->getResponse()->getStatusCode() ? 401 : 200;
    }

    private function connection(): ApiToken
    {
        $this->em->clear();
        $tokens = $this->em->getRepository(ApiToken::class)->findAll();
        self::assertCount(1, $tokens);
        self::assertNotNull($tokens[0]->oauthClientId());

        return $tokens[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $method, string $path): array
    {
        $this->client->request($method, $path);
        self::assertResponseIsSuccessful();

        return $this->decoded();
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $members = [];
        foreach ($decoded as $name => $value) {
            if (\is_string($name)) {
                $members[$name] = $value;
            }
        }

        return $members;
    }

    private function signIn(bool $twoFactor = true): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => IdentityFixtures::EMAIL, '_password' => IdentityFixtures::PASSWORD]));
        if ($twoFactor) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => IdentityFixtures::EMAIL]);
            self::assertInstanceOf(User::class, $user);
            $user->enrolTwoFactor(self::getContainer()->get(Sealer::class)->seal('JBSWY3DPEHPK3PXP'), ['recovery-hash']);
            self::getContainer()->get(UserRepository::class)->save($user);
        }
    }
}
