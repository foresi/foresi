<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Console;

use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user:reset-2fa',
    description: 'Turn off the two-factor sign-in of a user, who sets it up again on the next sign-in',
    help: <<<'HELP'
    Turns off the two-factor sign-in of a user and forgets their trusted browsers. Run it by hand when
    the person lost their authenticator and recovery codes. They set up two-factor sign-in again on the
    next sign-in. The reset is written to the log. It is safe to repeat. It exits with 1 when no user
    has the e-mail address.

      bin/console app:user:reset-2fa owner@example.com
    HELP,
)]
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
