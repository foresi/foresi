<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Domain;

use App\Identity\Domain\Invitation;
use App\Identity\Domain\InvitationState;
use App\Identity\Domain\InvitationToken;
use App\Identity\Domain\Role;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Shared\Domain\Id;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An invitation keeps only the hash of its token, works for a week and once,
 * and is for a member or a guest who represents a contact.
 */
final class InvitationTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-07 10:00:00');
    }

    public function testATokenIs32RandomBytesInBase64UrlAndOnlyItsHashIsKept(): void
    {
        $token = InvitationToken::generate();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        self::assertTrue(InvitationToken::isWellFormed($token));
        self::assertNotSame($token, InvitationToken::generate());
        self::assertSame(hash('sha256', $token), InvitationToken::hash($token));
        self::assertFalse(InvitationToken::isWellFormed($token.'='));
        self::assertFalse(InvitationToken::isWellFormed(substr($token, 1)));

        $invitation = $this->invitation(tokenHash: InvitationToken::hash($token));
        self::assertSame(InvitationToken::hash($token), $invitation->tokenHash());
    }

    public function testItIsPendingForAWeekThenExpires(): void
    {
        $invitation = $this->invitation();

        self::assertSame('jana@example.com', $invitation->email());
        self::assertEquals($this->now->modify('+7 days'), $invitation->expiresAt());
        self::assertTrue($invitation->isPending($this->now->modify('+7 days -1 second')));
        self::assertSame(InvitationState::Expired, $invitation->state($this->now->modify('+7 days')));
    }

    public function testItIsAcceptedOnce(): void
    {
        $invitation = $this->invitation();
        $invitation->accept($this->now);

        self::assertSame(InvitationState::Accepted, $invitation->state($this->now));
        $this->expectException(\DomainException::class);
        $invitation->accept($this->now);
    }

    public function testAnExpiredOrRevokedInvitationIsNotAccepted(): void
    {
        $expired = $this->invitation();
        try {
            $expired->accept($this->now->modify('+8 days'));
            self::fail('An expired invitation was accepted.');
        } catch (\DomainException) {
        }

        $revoked = $this->invitation();
        $revoked->revoke($this->now);
        self::assertSame(InvitationState::Revoked, $revoked->state($this->now));
        $this->expectException(\DomainException::class);
        $revoked->accept($this->now);
    }

    public function testRenewingGivesANewTokenForAnotherWeek(): void
    {
        $invitation = $this->invitation();
        $later = $this->now->modify('+10 days');
        $hash = InvitationToken::hash('another');

        $invitation->renew($hash, $later);

        self::assertSame($hash, $invitation->tokenHash());
        self::assertTrue($invitation->isPending($later));
        self::assertEquals($later->modify('+7 days'), $invitation->expiresAt());

        $invitation->revoke($later);
        $this->expectException(\DomainException::class);
        $invitation->renew(InvitationToken::hash('third'), $later);
    }

    public function testRevokingAnAcceptedInvitationChangesNothing(): void
    {
        $invitation = $this->invitation();
        $invitation->accept($this->now);
        $invitation->revoke($this->now);

        self::assertSame(InvitationState::Accepted, $invitation->state($this->now));
    }

    public function testRevokingAgainKeepsTheFirstMoment(): void
    {
        $invitation = $this->invitation();
        $invitation->revoke($this->now);
        $invitation->revoke($this->now->modify('+1 day'));

        self::assertEquals($this->now, new \ReflectionProperty($invitation, 'revokedAt')->getValue($invitation));
    }

    public function testRenewingNeedsTheHashOfATokenAndKeepsTheOldLinkOtherwise(): void
    {
        $invitation = $this->invitation();
        $hash = $invitation->tokenHash();

        foreach (['token', strtoupper(InvitationToken::hash('x')), InvitationToken::hash('x').'0', 'x'.InvitationToken::hash('x')] as $refused) {
            try {
                $invitation->renew($refused, $this->now->modify('+1 day'));
                self::fail('A hash that is not the SHA-256 of a token was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame($hash, $invitation->tokenHash());
        self::assertEquals($this->now->modify('+7 days'), $invitation->expiresAt());
    }

    public function testAnInvitationBeforeItIsPersistedHasNoTenant(): void
    {
        $this->expectException(\LogicException::class);

        $this->invitation()->tenantId();
    }

    public function testAGuestRepresentsAContact(): void
    {
        $party = new Party(PartyKind::Person, 'Alex Example', null, null, '');
        $invitation = $this->invitation(Role::Guest, $party);

        self::assertSame($party, $invitation->party());
        self::assertSame(Role::Guest, $invitation->role());
    }

    /**
     * @return iterable<string, array{string, string, Role, bool, string, string}>
     */
    public static function invalid(): iterable
    {
        $hash = InvitationToken::hash('token');
        yield 'e-mail' => ['not an address', 'Jana', Role::Member, false, 'cs', $hash];
        yield 'name' => ['jana@example.com', '  ', Role::Member, false, 'cs', $hash];
        yield 'owner' => ['jana@example.com', 'Jana', Role::Owner, false, 'cs', $hash];
        yield 'guest without contact' => ['jana@example.com', 'Jana', Role::Guest, false, 'cs', $hash];
        yield 'locale' => ['jana@example.com', 'Jana', Role::Member, false, 'de', $hash];
        yield 'raw token' => ['jana@example.com', 'Jana', Role::Member, false, 'cs', 'token'];
        yield 'token hash with a prefix' => ['jana@example.com', 'Jana', Role::Member, false, 'cs', 'x0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'];
        yield 'token hash with a suffix' => ['jana@example.com', 'Jana', Role::Member, false, 'cs', '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'."\n\n"];
    }

    #[DataProvider('invalid')]
    public function testAnIncompleteInvitationIsRefused(string $email, string $name, Role $role, bool $contact, string $locale, string $hash): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Invitation($email, $name, $role, $contact ? new Party(PartyKind::Person, 'Alex', null, null, '') : null, Id::generate(), $locale, $hash, $this->now);
    }

    private function invitation(Role $role = Role::Member, ?Party $party = null, ?string $tokenHash = null): Invitation
    {
        return new Invitation(' Jana@Example.com ', ' Jana Example ', $role, $party, Id::generate(), 'cs', $tokenHash ?? InvitationToken::hash('token'), $this->now);
    }
}
