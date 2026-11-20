<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure\Mail;

use Foresi\Invoicing\Application\MailGateway;
use Foresi\Invoicing\Application\MailHostPolicy;
use Foresi\Invoicing\Application\MailMessage;
use Foresi\Invoicing\Application\MailRefused;
use Foresi\Invoicing\Application\MailSecrets;
use Foresi\Invoicing\Domain\FirmMailServer;
use Foresi\Invoicing\Domain\MailEncryption;
use Foresi\Invoicing\Infrastructure\Mail\DnsMailHostPolicy;
use Foresi\Invoicing\Infrastructure\Mail\SodiumMailSecrets;
use Foresi\Kernel;
use Foresi\Tests\Support\Mail\RecordingMailGateway;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MailWiringTest extends KernelTestCase
{
    protected function setUp(): void
    {
        RecordingMailGateway::reset();
    }

    public function testTestAndE2eUseTheRecordingGateway(): void
    {
        self::bootKernel();
        self::assertSame(RecordingMailGateway::class, $this->classOf(self::getContainer(), MailGateway::class));
        self::assertSame(SodiumMailSecrets::class, $this->classOf(self::getContainer(), MailSecrets::class));
        self::assertSame(DnsMailHostPolicy::class, $this->classOf(self::getContainer(), MailHostPolicy::class));
        self::assertFalse(self::getContainer()->getParameter('app.mail.allow_private_hosts'));

        $e2e = new Kernel('e2e', false);
        $e2e->boot();
        try {
            self::assertSame(RecordingMailGateway::class, $this->classOf($e2e->getContainer(), MailGateway::class));
        } finally {
            $e2e->shutdown();
        }
    }

    public function testTheRecordingGatewayRecordsAndFailsOnRequest(): void
    {
        $gateway = new RecordingMailGateway();
        $server = $this->server();

        $gateway->send($this->message('customer@example.com'), $server);
        self::assertCount(1, $gateway->messages());
        self::assertSame(['customer@example.com'], $gateway->messages()[0]->to);

        $gateway->failNext(2, false);
        foreach ([1, 2] as $attempt) {
            try {
                $gateway->send($this->message('customer@example.com'), $server);
                self::fail(\sprintf('Attempt %d must fail.', $attempt));
            } catch (MailRefused $refused) {
                self::assertFalse($refused->permanent);
            }
        }
        $gateway->send($this->message('customer@example.com'), $server);
        self::assertCount(2, $gateway->messages());

        $gateway->failNext(1, true);
        try {
            $gateway->send($this->message('customer@example.com'), $server);
            self::fail('The failure must be permanent.');
        } catch (MailRefused $refused) {
            self::assertTrue($refused->permanent);
        }
        self::assertCount(2, $gateway->messages());
    }

    public function testTheRefusingRecipientAlwaysFailsPermanently(): void
    {
        $gateway = new RecordingMailGateway();

        foreach ([1, 2] as $attempt) {
            try {
                $gateway->send($this->message(RecordingMailGateway::REFUSING_RECIPIENT), $this->server());
                self::fail(\sprintf('Attempt %d must fail.', $attempt));
            } catch (MailRefused $refused) {
                self::assertTrue($refused->permanent);
                self::assertStringStartsWith('SMTP 550', $refused->safeReason);
            }
        }
        self::assertSame([], $gateway->messages());
    }

    /**
     * The class behind a service id. Read through a plain string, as the static
     * analysis knows only the services of the development environment.
     */
    private function classOf(ContainerInterface $container, string $id): string
    {
        return get_debug_type($container->get($id));
    }

    private function message(string $to): MailMessage
    {
        return new MailMessage('invoices@example.com', 'Example Firm', null, [$to], [], 'Subject', 'Text', '<p>Text</p>', '<1@example.com>');
    }

    private function server(): FirmMailServer
    {
        return FirmMailServer::define('smtp.example.com', 587, MailEncryption::Starttls, 'user', 'k1:sealed', 'invoices@example.com', 'Example Firm');
    }
}
