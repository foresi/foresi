<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Infrastructure\Security;

use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Application\ApiTokenRefused;
use Foresi\Identity\Application\InsufficientScope;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Infrastructure\Security\ApiTokenRejected;
use Foresi\Identity\Infrastructure\Security\SecurityApiTokenContext;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * The pieces between the firewall and the token: where the grant is read,
 * and the code of each refusal.
 */
final class ApiTokenSecurityTest extends TestCase
{
    private TokenStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new TokenStorage();
    }

    public function testWithoutASignInThereIsNoGrantAndNoScope(): void
    {
        $context = new SecurityApiTokenContext($this->storage);

        self::assertNull($context->grant());
        $this->expectException(InsufficientScope::class);
        $this->expectExceptionMessageIsOrContains('The token does not have the "read" scope.');
        $context->requireScope(ApiScope::read());
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
        $grant = $this->signIn([ApiScope::from('contacts:write')]);
        $context = new SecurityApiTokenContext($this->storage);

        self::assertSame($grant, $context->grant());
        self::assertSame($grant, $context->requireScope(ApiScope::from('contacts:write')));
        self::assertSame($grant, $context->requireScope(ApiScope::from('contacts:read')));
        try {
            $context->requireScope(ApiScope::from('invoices:read'));
            self::fail('A missing scope was allowed.');
        } catch (InsufficientScope $refused) {
            self::assertEquals(ApiScope::from('invoices:read'), $refused->scope);
        }
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
        $grant = new ApiTokenGrant('019a0000-0000-7000-8000-000000000001', '019a0000-0000-7000-8000-0000000000aa', 'Reporting', $scopes, new \DateTimeImmutable('2026-12-01'));
        $token = $this->token();
        $token->setAttribute(SecurityApiTokenContext::ATTRIBUTE, $grant);
        $this->storage->setToken($token);

        return $grant;
    }

    private function token(): PostAuthenticationToken
    {
        return new PostAuthenticationToken(new SecurityUser('019a0000-0000-7000-8000-000000000001', 'testing@example.com', 'hash'), 'api', ['ROLE_USER']);
    }
}
