<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Console;

use App\Identity\Application\UserRepository;
use App\Identity\Domain\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:reset-2fa', description: 'Turn off the two-factor sign-in of a user, who sets it up again on the next sign-in')]
final readonly class ResetTwoFactorCommand
{
    public function __construct(
        private UserRepository $users,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'E-mail address of the user')]
        string $email,
    ): int {
        $user = $this->users->findByEmail(strtolower(trim($email)));
        if (!$user instanceof User) {
            $io->error('No user has this e-mail.');

            return Command::FAILURE;
        }

        $user->resetTwoFactor();
        $this->users->save($user);
        $this->logger->notice('Two-factor sign-in was reset by the operator.', ['user' => $user->id()]);

        $io->success(sprintf('Two-factor sign-in of %s is reset; trusted browsers are forgotten.', $user->email()));

        return Command::SUCCESS;
    }
}
