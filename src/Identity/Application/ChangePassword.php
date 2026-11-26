<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Identity\Domain\User;
use Psr\Clock\ClockInterface;

/**
 * A person sets a new password for their own account. The current password is
 * the proof that it is them, so no separate confirmation is asked. The rules
 * follow NIST SP 800-63B: a minimum length and no composition rules, a long
 * passphrase is welcome, and a password known from a breach is refused.
 */
final readonly class ChangePassword
{
    /** The longest password the hasher takes (Symfony's PasswordHasherInterface::MAX_PASSWORD_LENGTH). */
    public const int MAX_LENGTH = 4096;

    public function __construct(
        private UserRepository $users,
        private PasswordHashing $hashing,
        private CompromisedPasswords $compromised,
        private PasswordChangedNotice $notice,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PasswordRefused
     */
    public function change(string $userId, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword): User
    {
        $user = $this->users->findById($userId) ?? throw PasswordRefused::unknownUser();
        if (!$this->hashing->verify($user->passwordHash(), $currentPassword)) {
            throw PasswordRefused::currentWrong();
        }
        if (mb_strlen($newPassword) < User::MIN_PASSWORD_LENGTH) {
            throw PasswordRefused::newPassword(PasswordRefused::TOO_SHORT, \sprintf('A password has at least %d characters.', User::MIN_PASSWORD_LENGTH));
        }
        if (\strlen($newPassword) > self::MAX_LENGTH) {
            throw PasswordRefused::newPassword(PasswordRefused::TOO_LONG, \sprintf('A password has at most %d characters.', self::MAX_LENGTH));
        }
        if ($this->compromised->isCompromised($newPassword)) {
            throw PasswordRefused::newPassword(PasswordRefused::COMPROMISED, 'The password is known from a data breach.');
        }
        $user->changePassword($this->hashing->hash($newPassword));
        $this->users->save($user);
        $this->notice->send($user, $this->clock->now());

        return $user;
    }
}
