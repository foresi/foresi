<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Application;

use Foresi\Identity\Application\ApiTokenAuthentication;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Application\ApiTokenRecord;
use Foresi\Identity\Application\ApiTokenRefused;
use Foresi\Identity\Application\MembershipDirectory;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiTokenSecret;
use Foresi\Identity\Domain\Role;
use Foresi\Tests\Identity\Support\InMemoryApiTokens;
use Foresi\Tests\Identity\Support\WorkApiResources;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Which secrets sign a request in, in what order the refusals are checked,
 * and how seldom the time of last use is written.
 */
final class ApiTokenAuthenticationTest extends TestCase
{
    private const string USER = '019a0000-0000-7000-8000-000000000001';
    private const string TOKEN = '019a0000-0000-7000-8000-0000000000aa';
    private const string CLIENT = '019a0000-0000-7000-8000-0000000000cc';

    private InMemoryApiTokens $tokens;
    private MockClock $clock;
    private string $secret;

    private ?Role $role = Role::Member;

    protected function setUp(): void
    {
        $this->tokens = new InMemoryApiTokens();
        $this->clock = new MockClock('2026-11-10 10:00:00');
        $this->secret = ApiTokenSecret::generate();
    }

    public function testAValidTokenGrantsItsPersonAndScopes(): void
    {
        $this->tokens->records = [$this->record()];

        $grant = $this->service()->authenticate($this->secret);

        self::assertSame([hash('sha256', $this->secret)], $this->tokens->hashesLookedUp);
        self::assertSame(self::USER, $grant->userId);
        self::assertSame(self::TOKEN, $grant->tokenId);
        self::assertSame('Reporting', $grant->tokenName);
        self::assertEquals([ApiScope::read()], $grant->scopes);
        self::assertEquals(new \DateTimeImmutable('2026-12-01 00:00:00'), $grant->expiresAt);
        self::assertTrue($grant->allows(ApiScope::read()));
        self::assertTrue($grant->allows(ApiScope::from('contacts:read')));
        self::assertFalse($grant->allows(ApiScope::write()));
        self::assertFalse($grant->allows(ApiScope::from('contacts:write')));
    }

    public function testTheOwnerSignsInToo(): void
    {
        $this->role = Role::Owner;
        $this->tokens->records = [$this->record()];

        self::assertSame(self::USER, $this->service()->authenticate($this->secret)->userId);
    }

    public function testAMalformedSecretIsNotLookedUp(): void
    {
        $this->tokens->records = [$this->record()];

        $this->expectRefusal(ApiTokenRefused::UNKNOWN, fn (): ApiTokenGrant => $this->service()->authenticate('foresi_pat_short'));
        self::assertSame([], $this->tokens->hashesLookedUp);
    }

    public function testAnUnknownSecretIsRefused(): void
    {
        $this->expectRefusal(ApiTokenRefused::UNKNOWN, fn (): ApiTokenGrant => $this->service()->authenticate($this->secret));
    }

    public function testATokenIsExpiredAtExactlyItsExpiry(): void
    {
        $this->tokens->records = [$this->record(expiresAt: '2026-11-10 10:00:00')];

        $this->expectRefusal(ApiTokenRefused::EXPIRED, fn (): ApiTokenGrant => $this->service()->authenticate($this->secret));
        self::assertSame([], $this->tokens->touches);
    }

    public function testATokenWorksUpToASecondBeforeItsExpiry(): void
    {
        $this->tokens->records = [$this->record(expiresAt: '2026-11-10 10:00:01')];

        self::assertSame(self::TOKEN, $this->service()->authenticate($this->secret)->tokenId);
    }

    public function testRevokedIsReportedBeforeExpired(): void
    {
        $this->tokens->records = [$this->record(expiresAt: '2026-11-01 00:00:00', revokedAt: '2026-10-30 00:00:00')];

        $this->expectRefusal(ApiTokenRefused::REVOKED, fn (): ApiTokenGrant => $this->service()->authenticate($this->secret));
    }

    public function testAPersonWhoLeftTheOrganisationIsRefused(): void
    {
        $this->role = null;
        $this->tokens->records = [$this->record()];

        $this->expectRefusal(ApiTokenRefused::MEMBERSHIP_GONE, fn (): ApiTokenGrant => $this->service()->authenticate($this->secret));
        self::assertSame([], $this->tokens->touches);
    }

    public function testAGuestsPersonalTokenIsRefused(): void
    {
        $this->role = Role::Guest;
        $this->tokens->records = [$this->record()];

        $this->expectRefusal(ApiTokenRefused::GUEST, fn (): ApiTokenGrant => $this->service()->authenticate($this->secret));
    }

    public function testAGuestsConnectionKeepsOnlyTheScopesAGuestMayHold(): void
    {
        $this->role = Role::Guest;
        $this->tokens->records = [$this->record(scopes: [ApiScope::write()], oauthClientId: self::CLIENT)];

        $grant = $this->service()->authenticate($this->secret);

        self::assertSame(['projects:read', 'tasks:write'], array_map(static fn (ApiScope $scope): string => $scope->value, $grant->scopes));
        self::assertTrue($grant->allows(ApiScope::from('tasks:write')));
        self::assertFalse($grant->allows(ApiScope::from('projects:write')));
        self::assertFalse($grant->allows(ApiScope::from('contacts:read')));
    }

    public function testAMemberWhoBecameAGuestKeepsNothingElse(): void
    {
        $this->role = Role::Guest;
        $this->tokens->records = [$this->record(scopes: [ApiScope::from('contacts:write')], oauthClientId: self::CLIENT)];

        $this->expectRefusal(ApiTokenRefused::GUEST, fn (): ApiTokenGrant => $this->service()->authenticate($this->secret));
    }

    public function testAFirstUseIsRecorded(): void
    {
        $this->tokens->records = [$this->record()];

        $this->service()->authenticate($this->secret);

        self::assertEquals([[self::TOKEN, new \DateTimeImmutable('2026-11-10 10:00:00'), new \DateTimeImmutable('2026-11-10 09:59:00')]], $this->tokens->touches);
    }

    #[DataProvider('lastUses')]
    public function testAUseIsRecordedAtMostOnceAMinute(string $lastUsedAt, int $touches): void
    {
        $this->tokens->records = [$this->record(lastUsedAt: $lastUsedAt)];

        $this->service()->authenticate($this->secret);

        self::assertCount($touches, $this->tokens->touches);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function lastUses(): iterable
    {
        yield '59 seconds ago' => ['2026-11-10 09:59:01', 0];
        yield '60 seconds ago' => ['2026-11-10 09:59:00', 1];
        yield '61 seconds ago' => ['2026-11-10 09:58:59', 1];
    }

    /**
     * @param list<ApiScope> $scopes
     */
    private function record(string $expiresAt = '2026-12-01 00:00:00', ?string $revokedAt = null, ?string $lastUsedAt = null, array $scopes = [], ?string $oauthClientId = null): ApiTokenRecord
    {
        return new ApiTokenRecord(
            self::TOKEN,
            self::USER,
            'Reporting',
            [] === $scopes ? [ApiScope::read()] : $scopes,
            'wxyz',
            new \DateTimeImmutable('2026-11-01 00:00:00'),
            new \DateTimeImmutable($expiresAt),
            null === $lastUsedAt ? null : new \DateTimeImmutable($lastUsedAt),
            null === $revokedAt ? null : new \DateTimeImmutable($revokedAt),
            $oauthClientId,
        );
    }

    private function expectRefusal(string $reason, callable $work): void
    {
        try {
            $work();
            self::fail('The token was not refused.');
        } catch (ApiTokenRefused $refused) {
            self::assertSame($reason, $refused->reason);
            self::assertSame(\sprintf('The API token was refused: %s.', $reason), $refused->getMessage());
        }
    }

    private function service(): ApiTokenAuthentication
    {
        $memberships = self::createStub(MembershipDirectory::class);
        $memberships->method('roleOf')->willReturnCallback(fn (string $userId): ?Role => self::USER === $userId ? $this->role : null);

        return new ApiTokenAuthentication($this->tokens, $memberships, $this->clock, WorkApiResources::guestScopes());
    }
}
