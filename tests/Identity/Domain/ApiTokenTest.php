<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Domain;

use App\Identity\Domain\ApiScope;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenSecret;
use App\Identity\Domain\ApiTokenState;
use App\Shared\Domain\Id;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiTokenTest extends TestCase
{
    private const string USER = '019a0000-0000-7000-8000-000000000001';

    public function testASecretHasThePrefixAndThirtyTwoRandomBytes(): void
    {
        $secret = ApiTokenSecret::generate();

        self::assertSame(54, \strlen($secret));
        self::assertStringStartsWith('foresi_pat_', $secret);
        self::assertTrue(ApiTokenSecret::isWellFormed($secret));
        self::assertNotSame($secret, ApiTokenSecret::generate());
        self::assertSame(hash('sha256', $secret), ApiTokenSecret::hash($secret));
        self::assertSame(substr($secret, -4), ApiTokenSecret::lastFour($secret));
    }

    public function testTheLastFourAreTheEndOfTheSecret(): void
    {
        self::assertSame('wxyz', ApiTokenSecret::lastFour('foresi_pat_abcdwxyz'));
    }

    #[DataProvider('malformedSecrets')]
    public function testAMalformedSecretIsNotOurs(string $secret): void
    {
        self::assertFalse(ApiTokenSecret::isWellFormed($secret));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedSecrets(): iterable
    {
        $body = str_repeat('a', 43);
        yield 'no prefix' => [$body];
        yield 'other prefix' => ['github_pat_'.$body];
        yield 'one short' => ['foresi_pat_'.str_repeat('a', 42)];
        yield 'one long' => ['foresi_pat_'.str_repeat('a', 44)];
        yield 'padding' => ['foresi_pat_'.str_repeat('a', 42).'='];
        yield 'leading text' => ['x foresi_pat_'.$body];
        yield 'trailing text' => ['foresi_pat_'.$body.' x'];
    }

    public function testATokenKeepsWhatItWasGiven(): void
    {
        $created = new \DateTimeImmutable('2026-11-10 10:00:00');
        $expires = new \DateTimeImmutable('2027-02-08 10:00:00');
        $token = new ApiToken(self::USER, '  Reporting script ', [ApiScope::Read, ApiScope::Write, ApiScope::Read], str_repeat('a', 64), 'wxyz', $created, $expires);

        self::assertTrue(Id::isValid($token->id()));
        self::assertSame(self::USER, $token->userId());
        self::assertSame('Reporting script', $token->name());
        self::assertSame([ApiScope::Read, ApiScope::Write], $token->scopes());
        self::assertSame('wxyz', $token->lastFour());
        self::assertSame($created, $token->createdAt());
        self::assertSame($expires, $token->expiresAt());
        self::assertNull($token->lastUsedAt());
        self::assertNull($token->revokedAt());
        self::assertNull($token->revokedBy());
    }

    public function testANameOfEightyCharactersIsAllowed(): void
    {
        self::assertSame(80, mb_strlen($this->token(str_repeat('č', 80))->name()));
    }

    /**
     * @param list<ApiScope> $scopes
     */
    #[DataProvider('invalidTokens')]
    public function testAnInvalidTokenIsRefused(string $user, string $name, array $scopes, string $hash, string $lastFour, string $expires): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApiToken($user, $name, $scopes, $hash, $lastFour, new \DateTimeImmutable('2026-11-10 10:00:00'), new \DateTimeImmutable($expires));
    }

    /**
     * @return iterable<string, array{string, string, list<ApiScope>, string, string, string}>
     */
    public static function invalidTokens(): iterable
    {
        $hash = str_repeat('a', 64);
        $later = '2026-11-11 10:00:00';
        yield 'no user' => ['', 'Name', [ApiScope::Read], $hash, 'wxyz', $later];
        yield 'blank name' => [self::USER, '   ', [ApiScope::Read], $hash, 'wxyz', $later];
        yield 'long name' => [self::USER, str_repeat('a', 81), [ApiScope::Read], $hash, 'wxyz', $later];
        yield 'no scope' => [self::USER, 'Name', [], $hash, 'wxyz', $later];
        yield 'short hash' => [self::USER, 'Name', [ApiScope::Read], str_repeat('a', 63), 'wxyz', $later];
        yield 'upper-case hash' => [self::USER, 'Name', [ApiScope::Read], str_repeat('A', 64), 'wxyz', $later];
        yield 'three last' => [self::USER, 'Name', [ApiScope::Read], $hash, 'xyz', $later];
        yield 'five last' => [self::USER, 'Name', [ApiScope::Read], $hash, 'vwxyz', $later];
        yield 'expires when created' => [self::USER, 'Name', [ApiScope::Read], $hash, 'wxyz', '2026-11-10 10:00:00'];
    }

    public function testATokenExpiresAtExactlyItsExpiry(): void
    {
        $token = $this->token();

        self::assertSame(ApiTokenState::Active, $token->state(new \DateTimeImmutable('2026-11-11 09:59:59')));
        self::assertSame(ApiTokenState::Expired, $token->state(new \DateTimeImmutable('2026-11-11 10:00:00')));
    }

    public function testRevokedOutranksExpired(): void
    {
        $token = $this->token();
        $token->revoke(self::USER, new \DateTimeImmutable('2026-11-10 12:00:00'));

        self::assertSame(ApiTokenState::Revoked, $token->state(new \DateTimeImmutable('2026-11-10 12:00:00')));
        self::assertSame(ApiTokenState::Revoked, $token->state(new \DateTimeImmutable('2026-12-01 00:00:00')));
    }

    public function testRevokingTwiceKeepsTheFirstTimeAndPerson(): void
    {
        $token = $this->token();
        $first = new \DateTimeImmutable('2026-11-10 12:00:00');
        $token->revoke(self::USER, $first);
        $token->revoke(null, new \DateTimeImmutable('2026-11-10 13:00:00'));

        self::assertSame($first, $token->revokedAt());
        self::assertSame(self::USER, $token->revokedBy());
    }

    public function testTheTenantIsStampedOnPersist(): void
    {
        $this->expectException(\LogicException::class);

        $this->token()->tenantId();
    }

    private function token(string $name = 'Name'): ApiToken
    {
        return new ApiToken(self::USER, $name, [ApiScope::Read], str_repeat('a', 64), 'wxyz', new \DateTimeImmutable('2026-11-10 10:00:00'), new \DateTimeImmutable('2026-11-11 10:00:00'));
    }
}
