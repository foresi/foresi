<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure\Security;

use App\Identity\Application\ApiTokenGrant;
use App\Identity\Application\ApiTokenRefused;
use App\Identity\Application\InsufficientScope;
use App\Identity\Domain\ApiScope;
use App\Identity\Infrastructure\Security\ApiTokenRejected;
use App\Identity\Infrastructure\Security\RequestActiveTenantStore;
use App\Identity\Infrastructure\Security\SecurityApiTokenContext;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\SessionActiveTenantStore;
use App\Identity\Infrastructure\Security\TokenActiveTenantStore;
use App\Tenancy\Domain\TenantId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * The pieces between the firewall and the token: where the grant is read,
 * which tenant store a request uses, and the code of each refusal.
 */
final class ApiTokenSecurityTest extends TestCase
{
    private TokenStorage $storage;
    private Session $session;
    private TenantId $tokenTenant;

    protected function setUp(): void
    {
        $this->storage = new TokenStorage();
        $this->session = new Session(new MockArraySessionStorage());
        $this->tokenTenant = TenantId::generate();
    }

    public function testWithoutASignInThereIsNoGrantAndNoScope(): void
    {
        $context = new SecurityApiTokenContext($this->storage);

        self::assertNull($context->grant());
        $this->expectException(InsufficientScope::class);
        $this->expectExceptionMessageIsOrContains('The token does not have the "read" scope.');
        $context->requireScope(ApiScope::Read);
    }

    public function testASignInWithoutATokenHasNoGrant(): void
    {
        $this->storage->setToken(new NullToken());

        self::assertNull(new SecurityApiTokenContext($this->storage)->grant());
    }

    public function testSomethingElseUnderTheAttributeIsNoGrant(): void
    {
        $token = $this->token();
        $token->setAttribute(SecurityApiTokenContext::ATTRIBUTE, 'forged');
        $this->storage->setToken($token);

        self::assertNull(new SecurityApiTokenContext($this->storage)->grant());
    }

    public function testTheGrantOfTheTokenIsReadAndItsScopesChecked(): void
    {
        $grant = $this->signIn([ApiScope::Write]);
        $context = new SecurityApiTokenContext($this->storage);

        self::assertSame($grant, $context->grant());
        self::assertSame($grant, $context->requireScope(ApiScope::Write));
        try {
            $context->requireScope(ApiScope::Read);
            self::fail('A missing scope was allowed.');
        } catch (InsufficientScope $refused) {
            self::assertSame(ApiScope::Read, $refused->scope);
        }
    }

    public function testATokenRequestUsesTheTokensTenantAndNeverTheSession(): void
    {
        $this->signIn([ApiScope::Read]);
        $other = TenantId::generate();
        $this->session->set('active_tenant', $other->toString());
        $store = $this->store();

        self::assertTrue($store->get()?->equals($this->tokenTenant));
        $store->store(TenantId::generate());
        $store->clear();
        $store->migrate();

        self::assertSame($other->toString(), $this->session->get('active_tenant'));
        self::assertTrue($store->get()?->equals($this->tokenTenant));
    }

    public function testAScreenRequestUsesTheSession(): void
    {
        $store = $this->store();
        $chosen = TenantId::generate();

        self::assertNull($store->get());
        $store->store($chosen);
        self::assertSame($chosen->toString(), $this->session->get('active_tenant'));
        self::assertTrue($store->get()?->equals($chosen));
        $id = $this->session->getId();
        $store->migrate();
        self::assertNotSame($id, $this->session->getId());
        $store->clear();
        self::assertNull($store->get());
    }

    #[DataProvider('rejections')]
    public function testEachRefusalHasItsProblemCode(string $reason, string $problem): void
    {
        $rejected = ApiTokenRejected::because($reason);

        self::assertSame($problem, $rejected->problem());
        self::assertSame('Invalid credentials.', $rejected->getMessageKey());
        self::assertSame('The API token was rejected.', $rejected->getMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejections(): iterable
    {
        yield 'expired' => [ApiTokenRefused::EXPIRED, 'token_expired'];
        yield 'revoked' => [ApiTokenRefused::REVOKED, 'token_revoked'];
        yield 'unknown' => [ApiTokenRefused::UNKNOWN, 'invalid_token'];
        yield 'membership gone' => [ApiTokenRefused::MEMBERSHIP_GONE, 'invalid_token'];
        yield 'guest' => [ApiTokenRefused::GUEST, 'invalid_token'];
    }

    /**
     * @param list<ApiScope> $scopes
     */
    private function signIn(array $scopes): ApiTokenGrant
    {
        $grant = new ApiTokenGrant('019a0000-0000-7000-8000-000000000001', $this->tokenTenant, '019a0000-0000-7000-8000-0000000000aa', 'Reporting', $scopes, new \DateTimeImmutable('2026-12-01'));
        $token = $this->token();
        $token->setAttribute(SecurityApiTokenContext::ATTRIBUTE, $grant);
        $this->storage->setToken($token);

        return $grant;
    }

    private function token(): PostAuthenticationToken
    {
        return new PostAuthenticationToken(new SecurityUser('019a0000-0000-7000-8000-000000000001', 'testing@example.com', 'hash'), 'api', ['ROLE_USER']);
    }

    private function store(): RequestActiveTenantStore
    {
        $request = new Request();
        $request->setSession($this->session);
        $requests = new RequestStack([$request]);
        $context = new SecurityApiTokenContext($this->storage);

        return new RequestActiveTenantStore($context, new TokenActiveTenantStore($context), new SessionActiveTenantStore($requests));
    }
}
