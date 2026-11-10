<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\Application\ManageApiTokens;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenSecret;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The api and mcp firewalls: a personal API token signs every request in, the
 * token's organisation is the active one, and nothing ever touches a session.
 * Every refusal is a problem document with a stable code.
 */
final class ApiTokenSignInTest extends WebTestCase
{
    private KernelBrowser $client;
    private IdentityFixtures $fixtures;
    private User $user;
    private TenantId $tenant;

    protected function tearDown(): void
    {
        $this->requireTwoFactor(false);
        parent::tearDown();
    }

    public function testWithoutATokenTheAnswerAsksForOne(): void
    {
        $this->start();

        $this->client->request('GET', '/api/v1/me');

        $this->assertProblem(401, 'missing_token');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer');
    }

    public function testAnotherSchemeCountsAsNoToken(): void
    {
        $this->start();

        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode('testing@example.com:'.IdentityFixtures::PASSWORD)]);

        $this->assertProblem(401, 'missing_token');
    }

    public function testATokenOfAnotherKindIsInvalid(): void
    {
        $this->start();

        $this->get('ghp_'.str_repeat('a', 36));

        $this->assertProblem(401, 'invalid_token');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer error="invalid_token"');
    }

    public function testAnUnknownTokenIsInvalid(): void
    {
        $this->start();

        $this->get(ApiTokenSecret::generate());

        $this->assertProblem(401, 'invalid_token');
    }

    public function testAnExpiredTokenSaysSo(): void
    {
        $this->start();
        $secret = $this->stored(new \DateTimeImmutable('-2 days'), new \DateTimeImmutable('-1 second'));

        $this->get($secret);

        $this->assertProblem(401, 'token_expired');
    }

    public function testARevokedTokenSaysSo(): void
    {
        $this->start();
        $secret = $this->stored(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day'), revoked: true);

        $this->get($secret);

        $this->assertProblem(401, 'token_revoked');
    }

    public function testTheTokenOfSomeoneWhoLeftReachesNothing(): void
    {
        $this->start();
        $secret = $this->issued();
        $this->context()->switchTo($this->tenant);
        $membership = $this->em()->getRepository(Membership::class)->findOneBy(['user' => $this->user->id()]);
        self::assertInstanceOf(Membership::class, $membership);
        $membership->deactivate(new \DateTimeImmutable());
        $this->em()->flush();
        $this->context()->reset();

        $this->get($secret);

        $this->assertProblem(401, 'invalid_token');
    }

    public function testTheTokenOfAGuestReachesNothing(): void
    {
        $this->start();
        $secret = $this->issued();
        $this->context()->switchTo($this->tenant);
        $membership = $this->em()->getRepository(Membership::class)->findOneBy(['user' => $this->user->id()]);
        self::assertInstanceOf(Membership::class, $membership);
        $membership->changeRole(Role::Guest, $this->party());
        $this->em()->flush();
        $this->context()->reset();

        $this->get($secret);

        $this->assertProblem(401, 'invalid_token');
    }

    public function testAValidTokenSaysWhoItActsAsWithoutACookie(): void
    {
        $this->start();
        $secret = $this->issued();

        $this->get($secret);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        $token = $this->token();
        self::assertSame([
            'user' => ['id' => $this->user->id(), 'email' => 'testing@example.com'],
            'tenant' => ['id' => $this->tenant->toString(), 'name' => 'Tenant A'],
            'token' => ['id' => $token->id(), 'name' => 'Reporting', 'scopes' => ['read'], 'expires_at' => $token->expiresAt()->format(\DATE_ATOM)],
        ], json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR));
        self::assertNotNull($token->lastUsedAt());
    }

    public function testTheTokensOrganisationIsTheActiveOneAmongSeveral(): void
    {
        $this->start();
        $other = $this->fixtures->tenant('Tenant B');
        $this->fixtures->join($this->user, $other, Role::Member);
        $secret = $this->issued($other);

        $this->get($secret);

        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $other->toString(), 'name' => 'Tenant B'], $this->json()['tenant']);
    }

    public function testATokenWorksWhereTwoFactorSignInIsRequiredOnTheScreens(): void
    {
        $this->requireTwoFactor(true);
        $this->start();
        $secret = $this->issued();

        $this->get($secret);

        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $this->user->id(), 'email' => 'testing@example.com'], $this->json()['user']);
    }

    public function testATokenWithoutTheReadScopeIsForbidden(): void
    {
        $this->start();
        $secret = $this->issued(scopes: [ApiScope::Write]);

        $this->get($secret);

        $this->assertProblem(403, 'insufficient_scope');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="read"');
    }

    public function testASignInOnTheScreensDoesNotReachTheApi(): void
    {
        $this->start();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => IdentityFixtures::EMAIL, '_password' => IdentityFixtures::PASSWORD]));

        $this->client->request('GET', '/api/v1/me');

        $this->assertProblem(401, 'missing_token');
    }

    public function testMcpNeedsAToken(): void
    {
        $this->start();

        $this->client->request('POST', '/mcp');

        $this->assertProblem(401, 'missing_token');
    }

    public function testMcpRefusesAnExpiredToken(): void
    {
        $this->start();

        $this->client->request('POST', '/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->stored(new \DateTimeImmutable('-2 days'), new \DateTimeImmutable('-1 second'))]);

        $this->assertProblem(401, 'token_expired');
    }

    public function testMcpLetsAValidTokenThroughWithoutACookie(): void
    {
        $this->start();
        $secret = $this->issued();

        $this->client->request('POST', '/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');

        self::assertNotSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    private function start(): void
    {
        $this->client = self::createClient();
        // The test database lives in memory, so every request must share the kernel.
        $this->client->disableReboot();
        $this->fixtures = new IdentityFixtures($this->em(), $this->context());
        $this->fixtures->rebuildSchema();
        $this->user = $this->fixtures->user();
        $this->tenant = $this->fixtures->tenant('Tenant A');
        $this->fixtures->join($this->user, $this->tenant, Role::Member);
    }

    /**
     * @param list<ApiScope> $scopes
     */
    private function issued(?TenantId $tenant = null, array $scopes = [ApiScope::Read]): string
    {
        $this->context()->switchTo($tenant ?? $this->tenant);
        try {
            return self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($this->user->id(), 'Reporting', $scopes)->secret;
        } finally {
            $this->context()->reset();
        }
    }

    private function stored(\DateTimeImmutable $created, \DateTimeImmutable $expires, bool $revoked = false): string
    {
        $secret = ApiTokenSecret::generate();
        $token = new ApiToken($this->user->id(), 'Reporting', [ApiScope::Read], ApiTokenSecret::hash($secret), ApiTokenSecret::lastFour($secret), $created, $expires);
        if ($revoked) {
            $token->revoke(null, $created);
        }
        $this->context()->switchTo($this->tenant);
        $this->em()->persist($token);
        $this->em()->flush();
        $this->context()->reset();

        return $secret;
    }

    private function token(): ApiToken
    {
        $this->em()->clear();
        $this->context()->reset();
        $this->context()->switchTo($this->tenant);
        $token = $this->em()->getRepository(ApiToken::class)->findOneBy([]);
        self::assertInstanceOf(ApiToken::class, $token);

        return $token;
    }

    private function party(): Party
    {
        $party = new Party(PartyKind::Person, 'Alex Example', null, null, '');
        $this->em()->persist($party);

        return $party;
    }

    private function get(string $secret): void
    {
        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
    }

    private function assertProblem(int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        $problem = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame('about:blank', $problem['type']);
        self::assertSame($status, $problem['status']);
        self::assertSame(401 === $status ? 'Unauthorized' : 'Forbidden', $problem['title']);
        self::assertSame($code, $problem['code']);
        self::assertIsString($problem['detail']);
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        /* @var array<string, mixed> $body */
        return $body;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function context(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function requireTwoFactor(bool $required): void
    {
        $_SERVER['APP_REQUIRE_2FA'] = $_ENV['APP_REQUIRE_2FA'] = $required ? 'true' : 'false';
    }
}
