<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\OAuth;

use Foresi\Audit\Application\AuditActor;
use Foresi\Audit\Domain\ActorKind;
use Foresi\Identity\Application\ApiTokenRefused;
use Foresi\Identity\Application\MembershipDirectory;
use Foresi\Identity\Application\OAuth\AuthorizationRequest;
use Foresi\Identity\Application\OAuth\ClientMetadataDocuments;
use Foresi\Identity\Application\OAuth\OAuthAuthorization;
use Foresi\Identity\Application\OAuth\OAuthClients;
use Foresi\Identity\Application\OAuth\OAuthRefused;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiTokenSecret;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\OAuth\OAuthClientKind;
use Foresi\Identity\Domain\OAuth\Pkce;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Shared\Application\Api\ApiResources;
use Foresi\Tests\Identity\Support\InMemoryOAuthStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The authorization endpoint without HTTP (ADR 0036): which requests reach the
 * consent screen, where each refusal goes, and what an answer redirects to.
 */
final class OAuthAuthorizationTest extends TestCase
{
    private const string RESOURCE = 'https://foresi.example.com/mcp';
    private const string ISSUER = 'https://foresi.example.com';
    private const string REDIRECT = 'https://client.example.com/callback';
    private const string CLIENT_ID = 'foresi_client_0123456789abcdef0123456789abcdef';

    private InMemoryOAuthStore $store;
    private MockClock $clock;
    private OAuthClient $client;
    private ?string $userId = '019a0000-0000-7000-8000-000000000001';
    private ?Role $role = Role::Member;
    private ?User $user;

    protected function setUp(): void
    {
        $this->store = new InMemoryOAuthStore();
        $this->clock = new MockClock('2026-11-18 10:00:00');
        $this->client = new OAuthClient(self::CLIENT_ID, OAuthClientKind::Registered, 'Example', [self::REDIRECT, 'https://client.example.com/cb?x=1'], $this->clock->now());
        $this->store->addClient($this->client);
        $this->user = new User('person@example.com', 'hash', 'Person');
        $this->user->enrolTwoFactor('k1:sealed', ['a']);
    }

    public function testAValidRequestReachesTheConsentScreen(): void
    {
        $request = $this->service()->check($this->query(['scope' => 'read contacts:write', 'state' => 'xyz', 'resource' => self::RESOURCE.'/']), self::RESOURCE);

        self::assertSame($this->client, $request->client);
        self::assertSame(self::REDIRECT, $request->redirectUri);
        self::assertSame('xyz', $request->state);
        self::assertSame(Pkce::challenge(str_repeat('v', 43)), $request->codeChallenge);
        self::assertSame(['read', 'contacts:write'], $this->values($request->scopes));
        self::assertSame(self::RESOURCE, $request->resource);
    }

    public function testAnEmptyStateIsNoStateAndNoScopeIsRead(): void
    {
        $request = $this->service()->check($this->query(['state' => '', 'scope' => '   ']), self::RESOURCE);

        self::assertNull($request->state);
        self::assertSame(['read'], $this->values($request->scopes));
        self::assertArrayNotHasKey('state', $request->parameters());
    }

    public function testOnlyKnownScopesOfDeclaredResourcesAreKept(): void
    {
        $request = $this->service()->check($this->query(['scope' => '  contacts:write  bogus! invoices:read read contacts:write ']), self::RESOURCE);

        self::assertSame(['contacts:write', 'read'], $this->values($request->scopes));
        self::assertSame([0, 1], array_keys($request->scopes));
    }

    public function testARequestOfNoKnownScopeGoesBackToTheClient(): void
    {
        $refused = $this->refusedCheck($this->query(['scope' => 'bogus! invoices:write', 'state' => 's']));

        self::assertSame(OAuthRefused::INVALID_SCOPE, $refused->error);
        self::assertSame(self::REDIRECT, $refused->redirectUri);
        self::assertSame('s', $refused->state);
    }

    public function testAnUnregisteredRedirectIsShownToThePerson(): void
    {
        foreach (['https://evil.example.com/callback', ''] as $redirect) {
            $refused = $this->refusedCheck($this->query(['redirect_uri' => $redirect]));

            self::assertSame(OAuthRefused::INVALID_REQUEST, $refused->error);
            self::assertSame('The redirect_uri is not one the client registered.', $refused->getMessage());
            self::assertNull($refused->redirectUri);
        }
    }

    public function testOnlyTheCodeFlowIsSupported(): void
    {
        $refused = $this->refusedCheck($this->query(['response_type' => 'token']));

        self::assertSame(OAuthRefused::UNSUPPORTED_RESPONSE_TYPE, $refused->error);
        self::assertSame(self::REDIRECT, $refused->redirectUri);
        self::assertNull($refused->state);
    }

    public function testPkceWithS256IsRequired(): void
    {
        foreach ([['code_challenge_method' => 'plain'], ['code_challenge' => 'short']] as $change) {
            $refused = $this->refusedCheck($this->query($change));

            self::assertSame(OAuthRefused::INVALID_REQUEST, $refused->error);
            self::assertSame(self::REDIRECT, $refused->redirectUri);
        }
    }

    public function testTheResourceMustBeTheMcpServer(): void
    {
        foreach (['https://other.example.com/mcp', ''] as $resource) {
            $refused = $this->refusedCheck($this->query(['resource' => $resource]));

            self::assertSame(OAuthRefused::INVALID_TARGET, $refused->error);
            self::assertSame('The resource parameter must be the URL of the MCP server, '.self::RESOURCE.'.', $refused->description);
        }
    }

    public function testTheResourceMayDifferInATrailingSlash(): void
    {
        self::assertTrue(OAuthAuthorization::sameResource(self::RESOURCE.'/', self::RESOURCE));
        self::assertTrue(OAuthAuthorization::sameResource(self::RESOURCE, self::RESOURCE.'/'));
        self::assertFalse(OAuthAuthorization::sameResource('', ''));
        self::assertFalse(OAuthAuthorization::sameResource(self::RESOURCE.'x', self::RESOURCE));
    }

    public function testApprovingKeepsTheReadsAndAddsTheGrantedWrites(): void
    {
        $request = $this->request([ApiScope::read(), ApiScope::from('contacts:read'), ApiScope::write()], 'st');

        $redirect = $this->service()->approve($request, [ApiScope::from('contacts:write'), ApiScope::from('invoices:write'), ApiScope::read()], self::ISSUER);

        self::assertCount(1, $this->store->codes);
        $code = $this->store->codes[0];
        self::assertSame(['read', 'contacts:read', 'contacts:write'], $this->values($code->scopes()));
        self::assertSame($this->client->id(), $code->clientId());
        self::assertSame($this->userId, $code->userId());
        self::assertSame(self::REDIRECT, $code->redirectUri());
        self::assertSame(self::RESOURCE, $code->resource());
        self::assertSame(1, $this->store->flushes);
        self::assertSame(1, preg_match('#^https://client\.example\.com/callback\?code=([A-Za-z0-9_-]+)&state=st&iss=https%3A%2F%2Fforesi\.example\.com$#', $redirect, $match));
        self::assertSame($code, $this->store->codeByHash(ApiTokenSecret::hash($match[1])));
    }

    public function testApprovingWithoutAnythingRequiredTakesTheGrantedScopes(): void
    {
        $request = $this->request([ApiScope::from('contacts:write'), ApiScope::write()], null);

        $redirect = $this->service()->approve($request, [ApiScope::from('contacts:write'), ApiScope::write()], self::ISSUER);

        self::assertSame(['contacts:write', 'write'], $this->values($this->store->codes[0]->scopes()));
        self::assertStringNotContainsString('state=', $redirect);
    }

    public function testApprovingNothingIsRefused(): void
    {
        $request = $this->request([ApiScope::write()], null);

        try {
            $this->service()->approve($request, [ApiScope::from('invoices:write')], self::ISSUER);
            self::fail('Nothing granted.');
        } catch (OAuthRefused $refused) {
            self::assertSame(OAuthRefused::INVALID_SCOPE, $refused->error);
            self::assertNull($refused->redirectUri);
        }
        self::assertSame([], $this->store->codes);
    }

    public function testARedirectWithAQueryGetsTheCodeAppended(): void
    {
        $request = new AuthorizationRequest($this->client, 'https://client.example.com/cb?x=1', null, Pkce::challenge(str_repeat('v', 43)), [ApiScope::read()], self::RESOURCE);

        self::assertStringStartsWith('https://client.example.com/cb?x=1&code=', $this->service()->approve($request, [], self::ISSUER));
    }

    public function testDenyingSendsAccessDenied(): void
    {
        self::assertSame(
            'https://client.example.com/callback?error=access_denied&error_description=The%20person%20did%20not%20allow%20access.&state=st&iss=https%3A%2F%2Fforesi.example.com',
            $this->service()->deny($this->request([ApiScope::read()], 'st'), self::ISSUER),
        );
    }

    public function testAnErrorGoesToTheRedirectUri(): void
    {
        $refused = new OAuthRefused(OAuthRefused::INVALID_SCOPE, 'No scope.')->at(self::REDIRECT, 'st');

        self::assertSame(
            'https://client.example.com/callback?error=invalid_scope&error_description=No%20scope.&state=st&iss=https%3A%2F%2Fforesi.example.com',
            OAuthAuthorization::errorRedirect(self::REDIRECT, $refused, self::ISSUER),
        );
    }

    public function testOnlyStaffWithTwoFactorSignInMayConnect(): void
    {
        self::assertSame($this->userId, $this->service()->connectingPerson());

        $this->assertConnectRefused(ApiTokenRefused::NOT_STAFF, fn (): Role => $this->role = Role::Guest);
        $this->assertConnectRefused(ApiTokenRefused::NOT_STAFF, fn (): null => $this->role = null);
        $this->assertConnectRefused(ApiTokenRefused::NOT_STAFF, fn (): null => $this->userId = null);
        $this->assertConnectRefused(ApiTokenRefused::TWO_FACTOR_REQUIRED, fn (): null => $this->user = null);
        $this->assertConnectRefused(ApiTokenRefused::TWO_FACTOR_REQUIRED, fn (): User => $this->user = new User('other@example.com', 'hash', 'Other'));
    }

    public function testTheRequestCarriesItsParametersThroughTheForm(): void
    {
        self::assertSame([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT,
            'state' => 'st',
            'code_challenge' => Pkce::challenge(str_repeat('v', 43)),
            'code_challenge_method' => 'S256',
            'scope' => 'read contacts:write',
            'resource' => self::RESOURCE,
        ], $this->request([ApiScope::read(), ApiScope::from('contacts:write')], 'st')->parameters());
    }

    public function testTheRequiredScopesAreTheReads(): void
    {
        $request = $this->request([ApiScope::write(), ApiScope::read(), ApiScope::from('contacts:write'), ApiScope::from('contacts:read')], null);

        self::assertSame(['read', 'contacts:read'], $this->values($request->requiredScopes()));
        self::assertSame([0, 1], array_keys($request->requiredScopes()));
    }

    /**
     * @param \Closure(): mixed $change
     */
    private function assertConnectRefused(string $reason, \Closure $change): void
    {
        $saved = [$this->userId, $this->role, $this->user];
        $change();
        try {
            $this->service()->connectingPerson();
            self::fail('Connecting is refused.');
        } catch (ApiTokenRefused $refused) {
            self::assertSame($reason, $refused->reason);
        } finally {
            [$this->userId, $this->role, $this->user] = $saved;
        }
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function query(array $changes = []): array
    {
        return [...[
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'code_challenge' => Pkce::challenge(str_repeat('v', 43)),
            'code_challenge_method' => 'S256',
            'resource' => self::RESOURCE,
        ], ...$changes];
    }

    /**
     * @param list<ApiScope> $scopes
     */
    private function request(array $scopes, ?string $state): AuthorizationRequest
    {
        return new AuthorizationRequest($this->client, self::REDIRECT, $state, Pkce::challenge(str_repeat('v', 43)), $scopes, self::RESOURCE);
    }

    /**
     * @param array<string, string> $query
     */
    private function refusedCheck(array $query): OAuthRefused
    {
        try {
            $this->service()->check($query, self::RESOURCE);
        } catch (OAuthRefused $refused) {
            return $refused;
        }
        self::fail('The request is refused.');
    }

    /**
     * @param array<ApiScope> $scopes
     *
     * @return list<string>
     */
    private function values(array $scopes): array
    {
        return array_values(array_map(static fn (ApiScope $scope): string => $scope->value, $scopes));
    }

    private function service(): OAuthAuthorization
    {
        $test = $this;
        $actor = new readonly class($test) implements AuditActor {
            public function __construct(private OAuthAuthorizationTest $test)
            {
            }

            public function userId(): ?string
            {
                return $this->test->currentUserId();
            }

            public function kind(): ActorKind
            {
                return ActorKind::User;
            }
        };
        $memberships = self::createStub(MembershipDirectory::class);
        $memberships->method('roleOf')->willReturn($this->role);
        $memberships->method('organisationOf')->willReturn(null);
        $users = self::createStub(UserRepository::class);
        $users->method('findById')->willReturn($this->user);
        $resources = self::createStub(ApiResources::class);
        $resources->method('declared')->willReturn(['contacts']);
        $documents = self::createStub(ClientMetadataDocuments::class);

        return new OAuthAuthorization(new OAuthClients($this->store, $documents, $this->clock), $this->store, $resources, $actor, $users, $memberships, $this->clock);
    }

    public function currentUserId(): ?string
    {
        return $this->userId;
    }
}
