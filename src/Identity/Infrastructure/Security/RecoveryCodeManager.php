<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\UserRepository;
use App\Identity\Domain\User;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Backup\BackupCodeManagerInterface;

/**
 * Lets a recovery code stand in for the authenticator code, once.
 */
final readonly class RecoveryCodeManager implements BackupCodeManagerInterface
{
    public function __construct(
        private UserRepository $users,
        private RecoveryCodes $codes,
        private LoggerInterface $logger,
    ) {
    }

    public function isBackupCode(object $user, #[\SensitiveParameter] string $code): bool
    {
        return $this->userOf($user)?->hasRecoveryCode($this->codes->hash($code)) ?? false;
    }

    public function invalidateBackupCode(object $user, #[\SensitiveParameter] string $code): void
    {
        $account = $this->userOf($user);
        if (!$account instanceof User || !$account->useRecoveryCode($this->codes->hash($code))) {
            return;
        }
        $this->users->save($account);
        $this->logger->notice('A recovery code was used to sign in.', ['user' => $account->id(), 'left' => $account->recoveryCodesLeft()]);
    }

    private function userOf(object $user): ?User
    {
        return $user instanceof SecurityUser ? $this->users->findById($user->id()) : null;
    }
}
