<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Application;

use Foresi\Identity\Application\ChangeEmail;
use Foresi\Identity\Application\EmailChangeMail;
use Foresi\Identity\Application\EmailChangeRefused;
use Foresi\Identity\Application\People;
use Foresi\Identity\Application\Person;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\EmailChangeToken;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Shared\Contract\RefusalKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ChangeEmailTest extends TestCase
{
    private MockClock $clock;
    private User $user;
    private User $other;
    private ChangeEmailUsers $users;
    private ChangeEmailMails $mail;
    /** @var list<Person> */
    private array $people = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-26 10:00:00');
        $this->user = new User('testing@example.com', 'hash', 'Example Person');
        $this->other = new User('other@example.com', 'hash', 'Other Person');
        $this->users = new ChangeEmailUsers([$this->user, $this->other]);
        $this->mail = new ChangeEmailMails();
    }

    public function testAStartedChangeWaitsForTheLinkSentToTheNewAddress(): void
    {
        $expires = $this->service()->start($this->user->id(), ' New@Example.com ');

        self::assertSame('2026-11-27 10:00:00', $expires->format('Y-m-d H:i:s'));
        self::assertSame('testing@example.com', $this->user->email());
        self::assertSame('new@example.com', $this->user->pendingEmail($this->clock->now()));
        self::assertSame(1, $this->users->saved);
        self::assertCount(1, $this->mail->confirmations);
        [$user, $email, $token, $expiresAt] = $this->mail->confirmations[0];
        self::assertSame($this->user, $user);
        self::assertSame('new@example.com', $email);
        self::assertTrue(EmailChangeToken::isWellFormed($token));
        self::assertSame($expires, $expiresAt);
        self::assertSame($this->user, $this->service()->open($token));
    }

    /**
     * @return iterable<string, array{string, string, RefusalKind, ?string}>
     */
    public static function refusedStarts(): iterable
    {
        yield 'not an address' => ['not-an-address', EmailChangeRefused::INVALID, RefusalKind::Invalid, EmailChangeRefused::FIELD];
        yield 'the current address' => ['TESTING@example.com', EmailChangeRefused::SAME, RefusalKind::Invalid, EmailChangeRefused::FIELD];
        yield 'another person\'s address' => ['Other@example.com', EmailChangeRefused::TAKEN, RefusalKind::Conflict, EmailChangeRefused::FIELD];
    }

    #[DataProvider('refusedStarts')]
    public function testARefusedStartChangesNothing(string $email, string $reason, RefusalKind $kind, ?string $field): void
    {
        try {
            $this->service()->start($this->user->id(), $email);
            self::fail('The change started.');
        } catch (EmailChangeRefused $refused) {
            self::assertSame($reason, $refused->reason());
            self::assertSame($kind, $refused->kind());
            self::assertSame($field, $refused->field());
            self::assertSame([], $refused->details());
        }
        self::assertNull($this->user->pendingEmail($this->clock->now()));
        self::assertSame(0, $this->users->saved);
        self::assertSame([], $this->mail->confirmations);
    }

    public function testWithoutMailNoChangeStarts(): void
    {
        $this->mail->configured = false;

        try {
            $this->service()->start($this->user->id(), 'new@example.com');
            self::fail('The change started.');
        } catch (EmailChangeRefused $refused) {
            self::assertSame(EmailChangeRefused::MAIL_UNAVAILABLE, $refused->reason());
            self::assertSame(RefusalKind::Unavailable, $refused->kind());
            self::assertNull($refused->field());
        }
        self::assertNull($this->user->pendingEmail($this->clock->now()));
    }

    public function testAnUnknownPersonStartsAndCancelsNothing(): void
    {
        try {
            $this->service()->start('missing', 'new@example.com');
            self::fail('The change started.');
        } catch (EmailChangeRefused $refused) {
            self::assertSame(EmailChangeRefused::UNKNOWN_USER, $refused->reason());
            self::assertSame(RefusalKind::NotFound, $refused->kind());
        }
        $this->expectException(EmailChangeRefused::class);
        $this->service()->cancel('missing');
    }

    public function testCancelEndsTheWaitAndTheLink(): void
    {
        $this->service()->start($this->user->id(), 'new@example.com');
        $token = $this->mail->confirmations[0][2];

        $this->service()->cancel($this->user->id());

        self::assertNull($this->user->pendingEmail($this->clock->now()));
        self::assertSame(2, $this->users->saved);
        self::assertNull($this->service()->open($token));
    }

    public function testConfirmationSwitchesTheAddressAndNoticesTheOldOneWithTheOtherOwners(): void
    {
        $this->people = [
            $this->person($this->user, Role::Owner, true),
            $this->person($this->other, Role::Owner, true),
            $this->person(new User('former@example.com', 'hash', 'Former Owner'), Role::Owner, false),
            $this->person(new User('member@example.com', 'hash', 'A Member'), Role::Member, true),
        ];
        $this->service()->start($this->user->id(), 'new@example.com');
        $this->clock->sleep(3600);

        $changed = $this->service()->confirm($this->mail->confirmations[0][2]);

        self::assertSame($this->user, $changed->user);
        self::assertSame('testing@example.com', $changed->oldEmail);
        self::assertSame('new@example.com', $this->user->email());
        self::assertSame(1, $this->user->sessionVersion());
        self::assertSame(2, $this->users->saved);
        self::assertCount(1, $this->mail->notices);
        [$user, $oldEmail, $changedAt, $owners] = $this->mail->notices[0];
        self::assertSame($this->user, $user);
        self::assertSame('testing@example.com', $oldEmail);
        self::assertSame('2026-11-26 11:00:00', $changedAt->format('Y-m-d H:i:s'));
        self::assertSame(['Other Person'], $owners);
    }

    public function testALinkWorksOnceAndForADay(): void
    {
        $this->service()->start($this->user->id(), 'new@example.com');
        $token = $this->mail->confirmations[0][2];

        $this->clock->sleep(24 * 3600);
        self::assertNull($this->service()->open($token));
        $this->assertLinkUnknown($token);

        $this->clock->modify('2026-11-26 12:00:00');
        $this->service()->confirm($token);
        $this->assertLinkUnknown($token);
        $this->assertLinkUnknown('not-a-token');
        $this->assertLinkUnknown(EmailChangeToken::generate());
        self::assertCount(1, $this->mail->notices);
    }

    public function testAnAddressTakenMeanwhileIsRefusedAtConfirmation(): void
    {
        $this->service()->start($this->user->id(), 'new@example.com');
        $this->users->add(new User('new@example.com', 'hash', 'Quicker Person'));

        try {
            $this->service()->confirm($this->mail->confirmations[0][2]);
            self::fail('The change was confirmed.');
        } catch (EmailChangeRefused $refused) {
            self::assertSame(EmailChangeRefused::TAKEN, $refused->reason());
        }
        self::assertSame('testing@example.com', $this->user->email());
        self::assertSame([], $this->mail->notices);
    }

    public function testOnlyKnownReasonsAreGiven(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EmailChangeRefused('whatever');
    }

    private function assertLinkUnknown(string $token): void
    {
        try {
            $this->service()->confirm($token);
            self::fail('The link worked.');
        } catch (EmailChangeRefused $refused) {
            self::assertSame(EmailChangeRefused::LINK_UNKNOWN, $refused->reason());
            self::assertSame('The e-mail change is refused: link_unknown.', $refused->getMessage());
        }
    }

    private function person(User $user, Role $role, bool $active): Person
    {
        return new Person('m-'.$user->id(), $user->id(), $user->displayName(), $user->email(), $role, null, null, false, $active);
    }

    private function service(): ChangeEmail
    {
        $people = $this->people;

        return new ChangeEmail($this->users, new readonly class($people) implements People {
            /**
             * @param list<Person> $people
             */
            public function __construct(private array $people)
            {
            }

            public function all(): array
            {
                return $this->people;
            }

            public function membership(string $id): ?Membership
            {
                return null;
            }

            public function membershipOf(string $userId): ?Membership
            {
                return null;
            }

            public function activeOwners(): int
            {
                return 0;
            }

            public function add(Membership $membership): void
            {
            }

            public function flush(): void
            {
            }
        }, $this->mail, $this->clock);
    }
}

/**
 * @internal
 */
final class ChangeEmailUsers implements UserRepository
{
    public int $saved = 0;

    /**
     * @param list<User> $users
     */
    public function __construct(private array $users)
    {
    }

    public function add(User $user): void
    {
        $this->users[] = $user;
    }

    public function findByEmail(string $email): ?User
    {
        return array_find($this->users, static fn (User $user): bool => $user->email() === strtolower(trim($email)));
    }

    public function findById(string $id): ?User
    {
        return array_find($this->users, static fn (User $user): bool => $user->id() === $id);
    }

    public function findByPendingEmailToken(string $tokenHash): ?User
    {
        $hashOf = \Closure::bind(static fn (User $user): ?string => $user->pendingEmailTokenHash, null, User::class);

        return array_find($this->users, static fn (User $user): bool => $hashOf($user) === $tokenHash);
    }

    public function namesOf(array $ids): array
    {
        return [];
    }

    public function save(User $user): void
    {
        ++$this->saved;
    }
}

/**
 * @internal
 */
final class ChangeEmailMails implements EmailChangeMail
{
    public bool $configured = true;
    /** @var list<array{User, string, string, \DateTimeImmutable}> */
    public array $confirmations = [];
    /** @var list<array{User, string, \DateTimeImmutable, list<string>}> */
    public array $notices = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function sendConfirmation(User $user, string $newEmail, string $token, \DateTimeImmutable $expiresAt): void
    {
        $this->confirmations[] = [$user, $newEmail, $token, $expiresAt];
    }

    public function sendNotice(User $user, string $oldEmail, \DateTimeImmutable $changedAt, array $owners): void
    {
        $this->notices[] = [$user, $oldEmail, $changedAt, $owners];
    }
}
