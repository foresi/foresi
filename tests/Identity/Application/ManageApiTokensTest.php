<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Application\ApiTokenRefused;
use App\Identity\Application\IssuedApiToken;
use App\Identity\Application\ManageApiTokens;
use App\Identity\Application\MembershipDirectory;
use App\Identity\Application\Organisation;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenSecret;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Application\TenantScope;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\InMemoryApiTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Who issues, lists and revokes tokens, and what is refused: the rules live
 * here, so the screens, the console and the API are refused alike.
 */
final class ManageApiTokensTest extends TestCase
{
    private InMemoryApiTokens $tokens;
    private TenantId $tenant;
    private MockClock $clock;
    private User $me;
    private User $colleague;
    private ?string $actorId;
    private bool $tenantActive = true;

    /** @var array<string, Role> role in the active tenant by user id */
    private array $roles = [];

    protected function setUp(): void
    {
        $this->tokens = new InMemoryApiTokens();
        $this->tenant = TenantId::generate();
        $this->clock = new MockClock('2026-11-10 10:00:00');
        $this->me = new User('testing@example.com', 'hash', 'Example Person');
        $this->me->enrolTwoFactor('sealed', ['code']);
        $this->colleague = new User('colleague@example.com', 'hash', 'Example Colleague');
        $this->actorId = $this->me->id();
        $this->roles = [$this->me->id() => Role::Member, $this->colleague->id() => Role::Member];
    }

    public function testIssuingGivesTheSecretOnceAndKeepsOnlyItsHash(): void
    {
        $issued = $this->service()->issue('  Reporting  ', [ApiScope::Read]);

        self::assertTrue(ApiTokenSecret::isWellFormed($issued->secret));
        self::assertSame([$issued->token], $this->tokens->tokens);
        self::assertSame(1, $this->tokens->flushes);
        self::assertSame($this->me->id(), $issued->token->userId());
        self::assertSame('Reporting', $issued->token->name());
        self::assertSame([ApiScope::Read], $issued->token->scopes());
        self::assertSame(substr($issued->secret, -4), $issued->token->lastFour());
        self::assertEquals(new \DateTimeImmutable('2026-11-10 10:00:00'), $issued->token->createdAt());
        self::assertEquals(new \DateTimeImmutable('2027-02-08 10:00:00'), $issued->token->expiresAt());
    }

    public function testTheDefaultsAreReadAndNinetyDays(): void
    {
        $token = $this->service()->issue('Reporting')->token;

        self::assertSame([ApiScope::Read], $token->scopes());
        self::assertEquals(new \DateTimeImmutable('2027-02-08 10:00:00'), $token->expiresAt());
    }

    #[DataProvider('lifetimes')]
    public function testEveryOfferedLifetimeIsIssued(int $days, string $expires): void
    {
        self::assertEquals(new \DateTimeImmutable($expires), $this->service()->issue('Reporting', [ApiScope::Read, ApiScope::Write], $days)->token->expiresAt());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function lifetimes(): iterable
    {
        yield '30' => [30, '2026-12-10 10:00:00'];
        yield '90' => [90, '2027-02-08 10:00:00'];
        yield '365' => [365, '2027-11-10 10:00:00'];
    }

    /**
     * @param list<ApiScope> $scopes
     */
    #[DataProvider('invalidRequests')]
    public function testAnInvalidRequestIsRefused(string $name, array $scopes, int $days, string $reason): void
    {
        $this->expectRefusal($reason, fn (): IssuedApiToken => $this->service()->issue($name, $scopes, $days));
        self::assertSame([], $this->tokens->tokens);
    }

    /**
     * @return iterable<string, array{string, list<ApiScope>, int, string}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'blank name' => [' ', [ApiScope::Read], 90, ApiTokenRefused::NAME];
        yield 'long name' => [str_repeat('a', 81), [ApiScope::Read], 90, ApiTokenRefused::NAME];
        yield 'no scope' => ['Name', [], 90, ApiTokenRefused::SCOPES];
        yield 'other lifetime' => ['Name', [ApiScope::Read], 31, ApiTokenRefused::EXPIRY];
        yield 'no lifetime' => ['Name', [ApiScope::Read], 0, ApiTokenRefused::EXPIRY];
    }

    public function testANameOfEightyCharactersIsIssued(): void
    {
        self::assertSame(str_repeat('ž', 80), $this->service()->issue(str_repeat('ž', 80))->token->name());
    }

    public function testIssuingNeedsTwoFactorSignIn(): void
    {
        $this->actorId = $this->colleague->id();

        $this->expectRefusal(ApiTokenRefused::TWO_FACTOR_REQUIRED, fn (): IssuedApiToken => $this->service()->issue('Reporting'));
    }

    public function testAGuestGetsNoToken(): void
    {
        $this->roles[$this->me->id()] = Role::Guest;

        $this->expectRefusal(ApiTokenRefused::NOT_STAFF, fn (): IssuedApiToken => $this->service()->issue('Reporting'));
    }

    public function testNobodySignedInGetsNoToken(): void
    {
        $this->actorId = null;

        $this->expectRefusal(ApiTokenRefused::NOT_STAFF, fn (): IssuedApiToken => $this->service()->issue('Reporting'));
    }

    public function testWithoutAnActiveOrganisationNobodyGetsAToken(): void
    {
        $this->tenantActive = false;

        $this->expectRefusal(ApiTokenRefused::NOT_STAFF, fn (): IssuedApiToken => $this->service()->issue('Reporting'));
    }

    public function testSomeoneOutsideTheOrganisationGetsNoToken(): void
    {
        unset($this->roles[$this->me->id()]);

        $this->expectRefusal(ApiTokenRefused::NOT_STAFF, fn (): IssuedApiToken => $this->service()->issue('Reporting'));
    }

    public function testTheOperatorIssuesWithoutTwoFactorSignIn(): void
    {
        $this->actorId = null;

        $issued = $this->service()->issueAsOperator($this->colleague->id(), 'Import', [ApiScope::Read], 30);

        self::assertSame($this->colleague->id(), $issued->token->userId());
        self::assertEquals(new \DateTimeImmutable('2026-12-10 10:00:00'), $issued->token->expiresAt());
    }

    public function testTheOperatorIssuesOnlyForTheStaff(): void
    {
        $this->roles[$this->colleague->id()] = Role::Guest;

        $this->expectRefusal(ApiTokenRefused::NOT_STAFF, fn (): IssuedApiToken => $this->service()->issueAsOperator($this->colleague->id(), 'Import'));
    }

    public function testTheOperatorDoesNotIssueForAStranger(): void
    {
        $this->expectRefusal(ApiTokenRefused::NOT_STAFF, fn (): IssuedApiToken => $this->service()->issueAsOperator('019a0000-0000-7000-8000-0000000000ff', 'Import'));
    }

    public function testMineAreOnlyMine(): void
    {
        $mine = $this->token($this->me);
        $this->token($this->colleague);

        self::assertSame([$mine], $this->service()->mine());
    }

    public function testAGuestListsNothing(): void
    {
        $this->roles[$this->me->id()] = Role::Guest;

        $this->expectRefusal(ApiTokenRefused::NOT_STAFF, fn (): array => $this->service()->mine());
    }

    public function testAnOwnerListsTheWholeOrganisation(): void
    {
        $this->roles[$this->me->id()] = Role::Owner;
        $mine = $this->token($this->me);
        $theirs = $this->token($this->colleague);

        self::assertSame([$mine, $theirs], $this->service()->organisation());
    }

    public function testAMemberDoesNotListTheWholeOrganisation(): void
    {
        $this->expectRefusal(ApiTokenRefused::NOT_YOURS, fn (): array => $this->service()->organisation());
    }

    public function testIRevokeMyOwnToken(): void
    {
        $token = $this->token($this->me);

        $this->service()->revoke($token->id());

        self::assertEquals(new \DateTimeImmutable('2026-11-10 10:00:00'), $token->revokedAt());
        self::assertSame($this->me->id(), $token->revokedBy());
        self::assertSame(1, $this->tokens->flushes);
    }

    public function testAMemberDoesNotRevokeSomeoneElsesToken(): void
    {
        $token = $this->token($this->colleague);

        $this->expectRefusal(ApiTokenRefused::NOT_YOURS, fn () => $this->service()->revoke($token->id()));
        self::assertNull($token->revokedAt());
        self::assertSame(0, $this->tokens->flushes);
    }

    public function testAnOwnerRevokesAnyTokenOfTheOrganisation(): void
    {
        $this->roles[$this->me->id()] = Role::Owner;
        $token = $this->token($this->colleague);

        $this->service()->revoke($token->id());

        self::assertSame($this->me->id(), $token->revokedBy());
    }

    public function testAnUnknownTokenIsNotRevoked(): void
    {
        $this->expectRefusal(ApiTokenRefused::UNKNOWN, fn () => $this->service()->revoke('019a0000-0000-7000-8000-0000000000ff'));
    }

    public function testAGuestRevokesNothing(): void
    {
        $token = $this->token($this->me);
        $this->roles[$this->me->id()] = Role::Guest;

        $this->expectRefusal(ApiTokenRefused::NOT_STAFF, fn () => $this->service()->revoke($token->id()));
    }

    public function testTheOperatorRevokesAnyToken(): void
    {
        $this->actorId = null;
        $token = $this->token($this->colleague);

        self::assertSame($token, $this->service()->revokeAsOperator($token->id()));
        self::assertEquals(new \DateTimeImmutable('2026-11-10 10:00:00'), $token->revokedAt());
        self::assertNull($token->revokedBy());
        self::assertSame(1, $this->tokens->flushes);
    }

    public function testTheOperatorDoesNotRevokeAnUnknownToken(): void
    {
        $this->expectRefusal(ApiTokenRefused::UNKNOWN, fn (): ApiToken => $this->service()->revokeAsOperator('019a0000-0000-7000-8000-0000000000ff'));
    }

    private function token(User $user): ApiToken
    {
        $token = new ApiToken($user->id(), 'Token', [ApiScope::Read], str_repeat('a', 64), 'wxyz', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-12-01'));
        $this->tokens->add($token);

        return $token;
    }

    private function expectRefusal(string $reason, callable $work): void
    {
        try {
            $work();
            self::fail('Nothing was refused.');
        } catch (ApiTokenRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    private function service(): ManageApiTokens
    {
        $users = self::createStub(UserRepository::class);
        $users->method('findById')->willReturnCallback(fn (string $id): ?User => [$this->me->id() => $this->me, $this->colleague->id() => $this->colleague][$id] ?? null);
        $memberships = self::createStub(MembershipDirectory::class);
        $memberships->method('organisationsOf')->willReturnCallback(fn (string $userId): array => isset($this->roles[$userId])
            ? [new Organisation(TenantId::generate(), 'Another Company', Role::Owner), new Organisation($this->tenant, 'Example Company', $this->roles[$userId])]
            : [new Organisation(TenantId::generate(), 'Another Company', Role::Owner)]);
        $actor = self::createStub(AuditActor::class);
        $actor->method('userId')->willReturn($this->actorId);
        $context = new TenantContext(self::createStub(TenantScope::class));
        if ($this->tenantActive) {
            $context->switchTo($this->tenant);
        }

        return new ManageApiTokens($this->tokens, $users, $memberships, $context, $actor, $this->clock);
    }
}
