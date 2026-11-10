<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure;

use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Identity\Application\ActiveTenantStore;
use App\Identity\Application\ApiTokenContext;
use App\Identity\Application\ApiTokenRecord;
use App\Identity\Application\ApiTokenRefused;
use App\Identity\Application\ApiTokens;
use App\Identity\Application\ManageApiTokens;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenState;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\ApiTokenLogin;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Tokens in the database: the reads across tenants, the throttled time of last
 * use, what the audit log keeps of a token, and a console process signed in
 * with one, whose changes the audit log puts on the token.
 */
final class ApiTokenPersistenceTest extends KernelTestCase
{
    private IdentityFixtures $fixtures;
    private User $user;
    private TenantId $tenant;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->fixtures = new IdentityFixtures($this->em(), $this->context());
        $this->fixtures->rebuildSchema();
        $this->user = $this->fixtures->user();
        $this->tenant = $this->fixtures->tenant('Tenant A');
        $this->fixtures->join($this->user, $this->tenant, Role::Member);
    }

    public function testTheHashAndTheLastUseNeverReachTheAuditLog(): void
    {
        $token = $this->issue($this->tenant);

        $this->context()->switchTo($this->tenant);
        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertSame(AuditAction::Created, $entries[0]->action());
        self::assertSame(ApiToken::class, $entries[0]->entityClass());
        self::assertSame(['name', 'scopes', 'userId', 'lastFour', 'createdAt', 'expiresAt'], array_keys($entries[0]->changes()));
        self::assertNull($entries[0]->apiTokenId());
        $this->context()->reset();

        $this->tokens()->touch($token->id(), new \DateTimeImmutable('2026-11-10 10:00:00'), new \DateTimeImmutable('2026-11-10 09:59:00'));

        $this->context()->switchTo($this->tenant);
        self::assertCount(1, $this->entries());
    }

    public function testAUseIsWrittenOnlyWhenTheLastOneIsOldEnough(): void
    {
        $token = $this->issue($this->tenant);
        $first = new \DateTimeImmutable('2026-11-10 10:00:00');

        $this->tokens()->touch($token->id(), $first, $first->modify('-60 seconds'));
        self::assertEquals($first, $this->record($token)->lastUsedAt);

        $this->tokens()->touch($token->id(), $first->modify('+59 seconds'), $first->modify('-1 second'));
        self::assertEquals($first, $this->record($token)->lastUsedAt);

        $this->tokens()->touch($token->id(), $first->modify('+60 seconds'), $first);
        self::assertEquals($first->modify('+60 seconds'), $this->record($token)->lastUsedAt);
    }

    public function testRecordsAreReadAcrossTenantsWithoutTheSecret(): void
    {
        $other = $this->fixtures->tenant('Tenant B');
        $this->fixtures->join($this->user, $other, Role::Owner);
        $first = $this->issue($this->tenant);
        $second = $this->issue($other);

        self::assertSame([$second->id(), $first->id()], array_map(static fn (ApiTokenRecord $record): string => $record->id, $this->tokens()->records(null)));
        $records = $this->tokens()->records($other);
        self::assertCount(1, $records);
        self::assertSame($second->id(), $records[0]->id);
        self::assertTrue($records[0]->tenantId->equals($other));
        self::assertSame($this->user->id(), $records[0]->userId);
        self::assertSame('Reporting', $records[0]->name);
        self::assertSame([ApiScope::Read], $records[0]->scopes);
        self::assertSame($second->lastFour(), $records[0]->lastFour);
        // The database keeps whole seconds.
        self::assertSame($second->expiresAt()->format(\DATE_ATOM), $records[0]->expiresAt->format(\DATE_ATOM));
        self::assertSame($second->createdAt()->format(\DATE_ATOM), $records[0]->createdAt->format(\DATE_ATOM));
        self::assertNull($records[0]->lastUsedAt);
        self::assertNull($records[0]->revokedAt);
        self::assertSame(ApiTokenState::Active, $records[0]->state(new \DateTimeImmutable()));
        self::assertTrue($this->tokens()->tenantOf($second->id())?->equals($other));
        self::assertNull($this->tokens()->tenantOf('019a0000-0000-7000-8000-0000000000ff'));
        self::assertNull($this->tokens()->byHash(str_repeat('0', 64)));
    }

    public function testTheTokensOfTheActiveTenantStayInIt(): void
    {
        $other = $this->fixtures->tenant('Tenant B');
        $this->fixtures->join($this->user, $other, Role::Owner);
        $mine = $this->issue($this->tenant);
        $this->issue($other);

        $this->context()->switchTo($this->tenant);
        self::assertSame([$mine->id()], array_map(static fn (ApiToken $token): string => $token->id(), $this->tokens()->all()));
        self::assertSame([$mine->id()], array_map(static fn (ApiToken $token): string => $token->id(), $this->tokens()->ofUser($this->user->id())));
        self::assertSame([], $this->tokens()->ofUser('019a0000-0000-7000-8000-0000000000ff'));
        self::assertSame($mine->id(), $this->tokens()->find($mine->id())?->id());
    }

    public function testAConsoleSignInActsAsTheTokenAndItsOrganisation(): void
    {
        $this->context()->switchTo($this->tenant);
        $issued = self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($this->user->id(), 'Assistant');
        $this->context()->reset();

        $grant = self::getContainer()->get(ApiTokenLogin::class)->asConsole($issued->secret);

        self::assertSame($issued->token->id(), $grant->tokenId);
        self::assertTrue($this->context()->current()->equals($this->tenant));
        $user = self::getContainer()->get(TokenStorageInterface::class)->getToken()?->getUser();
        self::assertInstanceOf(SecurityUser::class, $user);
        self::assertSame($this->user->id(), $user->id());
        self::assertSame($grant, self::getContainer()->get(ApiTokenContext::class)->grant());
        self::assertTrue(self::getContainer()->get(ActiveTenantStore::class)->get()?->equals($this->tenant));
        self::assertNotNull($this->record($issued->token)->lastUsedAt);

        // A change made now is the token's, next to its person.
        self::getContainer()->get(ManageApiTokens::class)->revoke($issued->token->id());
        $entries = array_values(array_filter($this->entries(), static fn (AuditEntry $entry): bool => AuditAction::Updated === $entry->action()));
        self::assertCount(1, $entries);
        self::assertSame($issued->token->id(), $entries[0]->apiTokenId());
        self::assertSame($this->user->id(), $entries[0]->userId());
        self::assertSame(['revokedAt', 'revokedBy'], array_keys($entries[0]->changes()));
    }

    public function testARefusedConsoleSignInLeavesNobodySignedIn(): void
    {
        try {
            self::getContainer()->get(ApiTokenLogin::class)->asConsole('foresi_pat_nothing');
            self::fail('The token was accepted.');
        } catch (ApiTokenRefused $refused) {
            self::assertSame(ApiTokenRefused::UNKNOWN, $refused->reason);
        }

        self::assertNull(self::getContainer()->get(TokenStorageInterface::class)->getToken());
        self::assertFalse($this->context()->has());
        self::assertNull(self::getContainer()->get(ApiTokenContext::class)->grant());
    }

    private function issue(TenantId $tenant): ApiToken
    {
        $this->context()->switchTo($tenant);
        try {
            return self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($this->user->id(), 'Reporting')->token;
        } finally {
            $this->context()->reset();
        }
    }

    private function record(ApiToken $token): ApiTokenRecord
    {
        $records = array_values(array_filter($this->tokens()->records(null), static fn (ApiTokenRecord $record): bool => $record->id === $token->id()));
        self::assertCount(1, $records);

        return $records[0];
    }

    /**
     * @return list<AuditEntry>
     */
    private function entries(): array
    {
        /* @var list<AuditEntry> */
        return $this->em()->createQuery('SELECT e FROM '.AuditEntry::class.' e WHERE e.entityClass = :class ORDER BY e.occurredAt, e.id')
            ->setParameter('class', ApiToken::class)
            ->getResult();
    }

    private function tokens(): ApiTokens
    {
        return self::getContainer()->get(ApiTokens::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function context(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
