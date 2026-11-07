<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mail;

use App\Shared\Application\SystemMail;
use App\Shared\Application\SystemMailer;
use App\Shared\Application\SystemMailUnavailable;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Queues one test mail through the system mailer, so the operator can check
 * SYSTEM_MAILER_DSN and SYSTEM_MAIL_FROM. The worker sends it.
 */
#[AsCommand(name: 'app:ops:test-mail', description: 'Send a test system mail to check the system mailer')]
final readonly class TestMailCommand
{
    public function __construct(
        private SystemMailer $mailer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The address to send the test mail to')]
        string $to,
    ): int {
        try {
            $this->mailer->send(new SystemMail($to, 'Test mail', 'This is a test mail from app:ops:test-mail. The system mail of this installation works.', null, 'en'));
        } catch (SystemMailUnavailable $unavailable) {
            $io->error($unavailable->getMessage().' Set SYSTEM_MAILER_DSN and SYSTEM_MAIL_FROM.');

            return Command::FAILURE;
        } catch (\InvalidArgumentException $invalid) {
            $io->error($invalid->getMessage());

            return Command::FAILURE;
        }
        $io->success('The test mail is queued. The worker sends it on the mail transport.');

        return Command::SUCCESS;
    }
}
