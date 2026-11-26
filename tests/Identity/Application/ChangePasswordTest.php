<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Application;

use Foresi\Identity\Application\ChangePassword;
use Foresi\Identity\Application\CompromisedPasswords;
use Foresi\Identity\Application\PasswordChangedNotice;
use Foresi\Identity\Application\PasswordHashing;
use Foresi\Identity\Application\PasswordRefused;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\User;
use Foresi\Shared\Contract\RefusalKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ChangePasswordTest extends TestCase
{
    private const string CURRENT = 'the-current-password';

    private User $user;
    /** @var list<array{User, \DateTimeImmutable}> */
    private array $notices = [];
    /** @var list<string> */
    private array $compromised = [];
    private int $saved = 0;

    protected function setUp(): void
    {
        $this->user = new User('testing@example.com', 'hashed:'.self::CURRENT, 'Example Person');
    }

    public function testANewPasswordIsHashedSavedAndNoticed(): void
    {
        $this->service()->change($this->user->id(), self::CURRENT, 'a long new passphrase');

        self::assertSame('hashed:a long new passphrase', $this->user->passwordHash());
        self::assertSame(1, $this->user->trustedDeviceVersion());
        self::assertSame(1, $this->saved);
        self::assertCount(1, $this->notices);
        self::assertSame($this->user, $this->notices[0][0]);
        self::assertSame('2026-11-26 10:00:00', $this->notices[0][1]->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'wrong current password' => ['not-the-password', 'a long new passphrase', PasswordRefused::CURRENT_WRONG, PasswordRefused::CURRENT_FIELD];
        yield 'empty current password' => ['', 'a long new passphrase', PasswordRefused::CURRENT_WRONG, PasswordRefused::CURRENT_FIELD];
        yield 'too short' => [self::CURRENT, str_repeat('a', User::MIN_PASSWORD_LENGTH - 1), PasswordRefused::TOO_SHORT, PasswordRefused::NEW_FIELD];
        yield 'too short in characters though long in bytes' => [self::CURRENT, str_repeat('é', User::MIN_PASSWORD_LENGTH - 1), PasswordRefused::TOO_SHORT, PasswordRefused::NEW_FIELD];
        yield 'too long' => [self::CURRENT, str_repeat('a', ChangePassword::MAX_LENGTH + 1), PasswordRefused::TOO_LONG, PasswordRefused::NEW_FIELD];
        yield 'compromised' => [self::CURRENT, 'password1234', PasswordRefused::COMPROMISED, PasswordRefused::NEW_FIELD];
    }

    #[DataProvider('refusals')]
    public function testARefusedPasswordChangesNothing(string $current, string $new, string $reason, string $field): void
    {
        $this->compromised = ['password1234'];

        try {
            $this->service()->change($this->user->id(), $current, $new);
            self::fail('The password was changed.');
        } catch (PasswordRefused $refused) {
            self::assertSame($reason, $refused->reason());
            self::assertSame($field, $refused->field());
        }

        self::assertSame('hashed:'.self::CURRENT, $this->user->passwordHash());
        self::assertSame(0, $this->user->trustedDeviceVersion());
        self::assertSame(0, $this->saved);
        self::assertSame([], $this->notices);
    }

    public function testTheShortestAndTheLongestPasswordsAreAccepted(): void
    {
        $this->service()->change($this->user->id(), self::CURRENT, str_repeat('é', User::MIN_PASSWORD_LENGTH));
        $this->service()->change($this->user->id(), str_repeat('é', User::MIN_PASSWORD_LENGTH), str_repeat('a', ChangePassword::MAX_LENGTH));

        self::assertSame(2, $this->saved);
    }

    public function testAnUnknownPersonIsRefusedAsNotFound(): void
    {
        try {
            $this->service()->change('no-such-id', self::CURRENT, 'a long new passphrase');
            self::fail('The password was changed.');
        } catch (PasswordRefused $refused) {
            self::assertSame(RefusalKind::NotFound, $refused->kind());
            self::assertNull($refused->field());
            self::assertSame('The person does not exist.', $refused->getMessage());
        }

        self::assertSame(0, $this->saved);
    }

    public function testARefusalCarriesAMessageForThePerson(): void
    {
        try {
            $this->service()->change($this->user->id(), 'not-the-password', 'a long new passphrase');
            self::fail('The password was changed.');
        } catch (PasswordRefused $refused) {
            self::assertSame('The current password is not right.', $refused->getMessage());
            self::assertSame(RefusalKind::Invalid, $refused->kind());
        }
    }

    private function service(): ChangePassword
    {
        $test = $this;
        $users = new readonly class($test) implements UserRepository {
            public function __construct(private ChangePasswordTest $test)
            {
            }

            public function findByEmail(string $email): ?User
            {
                return null;
            }

            public function findById(string $id): ?User
            {
                return $this->test->user($id);
            }

            public function namesOf(array $ids): array
            {
                return [];
            }

            public function save(User $user): void
            {
                $this->test->saved();
            }
        };
        $hashing = new class implements PasswordHashing {
            public function hash(string $password): string
            {
                return 'hashed:'.$password;
            }

            public function verify(string $hash, string $password): bool
            {
                return 'hashed:'.$password === $hash;
            }
        };
        $compromised = new readonly class($test) implements CompromisedPasswords {
            public function __construct(private ChangePasswordTest $test)
            {
            }

            public function isCompromised(string $password): bool
            {
                return $this->test->isCompromised($password);
            }
        };
        $notice = new readonly class($test) implements PasswordChangedNotice {
            public function __construct(private ChangePasswordTest $test)
            {
            }

            public function send(User $user, \DateTimeImmutable $changedAt): void
            {
                $this->test->noticed($user, $changedAt);
            }
        };

        return new ChangePassword($users, $hashing, $compromised, $notice, new MockClock('2026-11-26 10:00:00'));
    }

    /** @internal for the fakes above */
    public function user(string $id): ?User
    {
        return $id === $this->user->id() ? $this->user : null;
    }

    /** @internal for the fakes above */
    public function saved(): void
    {
        ++$this->saved;
    }

    /** @internal for the fakes above */
    public function isCompromised(string $password): bool
    {
        return \in_array($password, $this->compromised, true);
    }

    /** @internal for the fakes above */
    public function noticed(User $user, \DateTimeImmutable $changedAt): void
    {
        $this->notices[] = [$user, $changedAt];
    }
}
