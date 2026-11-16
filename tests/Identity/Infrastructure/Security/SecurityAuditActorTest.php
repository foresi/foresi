<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure\Security;

use App\Audit\Domain\ActorKind;
use App\Identity\Application\ApiTokenGrant;
use App\Identity\Domain\ApiScope;
use App\Identity\Infrastructure\Security\ApiFirewalls;
use App\Identity\Infrastructure\Security\SecurityApiTokenContext;
use App\Identity\Infrastructure\Security\SecurityAuditActor;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Tenancy\Domain\TenantId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class SecurityAuditActorTest extends TestCase
{
    private const string USER = '019a0000-0000-7000-8000-0000000000aa';

    private TokenStorage $storage;
    private SecurityAuditActor $actor;

    protected function setUp(): void
    {
        $this->storage = new TokenStorage();
        $this->actor = new SecurityAuditActor($this->storage, new SecurityApiTokenContext($this->storage));
    }

    public function testNobodySignedInIsTheSystem(): void
    {
        self::assertSame(ActorKind::System, $this->actor->kind());
        self::assertNull($this->actor->userId());
    }

    public function testASignInOnTheScreensIsAUser(): void
    {
        $this->storage->setToken(new UsernamePasswordToken($this->user(), 'main', ['ROLE_USER']));

        self::assertSame(ActorKind::User, $this->actor->kind());
        self::assertSame(self::USER, $this->actor->userId());
    }

    #[DataProvider('firewalls')]
    public function testATokenGrantTakesItsKindFromTheFirewall(string $firewall, ActorKind $kind): void
    {
        $token = new PostAuthenticationToken($this->user(), $firewall, ['ROLE_USER']);
        $token->setAttribute(SecurityApiTokenContext::ATTRIBUTE, new ApiTokenGrant(self::USER, TenantId::generate(), '019a0000-0000-7000-8000-000000000001', 'Reporting', [ApiScope::Read], new \DateTimeImmutable('2026-12-01')));
        $this->storage->setToken($token);

        self::assertSame($kind, $this->actor->kind());
    }

    /**
     * @return iterable<string, array{string, ActorKind}>
     */
    public static function firewalls(): iterable
    {
        yield 'REST api' => [ApiFirewalls::API, ActorKind::ApiToken];
        yield 'MCP agent' => [ApiFirewalls::MCP, ActorKind::McpAgent];
    }

    public function testAPostAuthenticationTokenWithoutAGrantIsAUser(): void
    {
        $this->storage->setToken(new PostAuthenticationToken($this->user(), ApiFirewalls::MCP, ['ROLE_USER']));

        self::assertSame(ActorKind::User, $this->actor->kind());
    }

    private function user(): SecurityUser
    {
        return new SecurityUser(self::USER, 'testing@example.com', 'hash');
    }
}
