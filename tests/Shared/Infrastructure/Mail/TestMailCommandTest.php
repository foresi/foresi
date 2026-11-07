<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Mail;

use App\Shared\Application\SystemMailUnavailable;
use App\Shared\Infrastructure\Mail\TestMailCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The operator checks the system mailer: one test mail is queued, or the command
 * says why none can be.
 */
final class TestMailCommandTest extends KernelTestCase
{
    public function testItQueuesOneTestMail(): void
    {
        $mailer = $this->recording();
        $output = new BufferedOutput();

        $status = new TestMailCommand($mailer)(new SymfonyStyle(new ArrayInput([]), $output), 'jana@example.com');

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(1, $mailer->sent);
        self::assertSame('jana@example.com', $mailer->sent[0]->to);
        self::assertSame('en', $mailer->sent[0]->locale);
        self::assertStringContainsString('app:ops:test-mail', $mailer->sent[0]->text);
        self::assertStringContainsString('queued', $output->fetch());
    }

    public function testAnUnconfiguredInstallationFails(): void
    {
        $output = new BufferedOutput();

        $status = new TestMailCommand($this->recording(new SystemMailUnavailable()))(new SymfonyStyle(new ArrayInput([]), $output), 'jana@example.com');

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('SYSTEM_MAILER_DSN', $output->fetch());
    }

    public function testAMalformedAddressFails(): void
    {
        $output = new BufferedOutput();

        $status = new TestMailCommand($this->recording(new \InvalidArgumentException('Bad address.')))(new SymfonyStyle(new ArrayInput([]), $output), 'nope');

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Bad address.', $output->fetch());
    }

    public function testItIsRegistered(): void
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('app:ops:test-mail'));

        self::assertSame(Command::SUCCESS, $tester->execute(['to' => 'jana@example.com']));
        self::assertStringContainsString('queued', $tester->getDisplay());
    }

    private function recording(?\Throwable $failure = null): RecordingSystemMailer
    {
        return new RecordingSystemMailer($failure);
    }
}
