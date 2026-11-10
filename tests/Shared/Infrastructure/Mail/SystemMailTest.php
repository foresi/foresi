<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Mail;

use App\Shared\Application\SystemMail;
use App\Shared\Application\SystemMailUnavailable;
use App\Shared\Infrastructure\Mail\DeliverSystemMailHandler;
use App\Shared\Infrastructure\Mail\MessengerSystemMailer;
use App\Shared\Infrastructure\Mail\SystemMailSettings;
use App\Tests\Shared\Infrastructure\Logging\RecordingTransport;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * System mail of the installation: queued on the mail transport, sent through the
 * installation's own mailer from SYSTEM_MAIL_FROM in the layout of the product,
 * and refused with a clear error while the installation has no mailer.
 */
final class SystemMailTest extends KernelTestCase
{
    public function testSendingQueuesTheMailOnTheMailTransport(): void
    {
        self::bootKernel();
        $mailer = $this->mailer(new SystemMailSettings('smtp://localhost:25', 'Foresi <noreply@example.com>'));
        $mail = new SystemMail('jana@example.com', 'Invitation', 'Join us.', null, 'en', 'Example Ltd');

        $mailer->send($mail);

        $transport = self::getContainer()->get('messenger.transport.mail');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertSame($mail, $sent[0]->getMessage());
    }

    public function testWithoutAMailerOrASenderTheInstallationCannotSend(): void
    {
        self::bootKernel();
        foreach ([new SystemMailSettings('', 'Foresi <noreply@example.com>'), new SystemMailSettings('smtp://localhost:25', ''), new SystemMailSettings('smtp://localhost:25', 'not an address')] as $settings) {
            $mailer = $this->mailer($settings);
            self::assertFalse($mailer->isConfigured());
            try {
                $mailer->send(new SystemMail('jana@example.com', 'Invitation', 'Join us.', null, 'en'));
                self::fail('An unconfigured installation sent mail.');
            } catch (SystemMailUnavailable $unavailable) {
                self::assertSame('This installation cannot send e-mail yet.', $unavailable->getMessage());
            }
        }
        $transport = self::getContainer()->get('messenger.transport.mail');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertSame([], $transport->getSent());
    }

    public function testAMalformedRecipientIsRefusedBeforeItIsQueued(): void
    {
        self::bootKernel();

        $this->expectException(\InvalidArgumentException::class);
        $this->mailer(new SystemMailSettings('smtp://localhost:25', 'noreply@example.com'))->send(new SystemMail('not an address', 'Invitation', 'Join us.', null, 'en'));
    }

    public function testTheHandlerSendsFromTheInstallationInTheLayout(): void
    {
        self::bootKernel();
        $transport = new RecordingTransport();
        $handler = new DeliverSystemMailHandler(SystemMailSettings::through($transport, 'Foresi <noreply@example.com>'), self::getContainer()->get(Environment::class));

        $handler(new SystemMail('jana@example.com', 'Pozvánka', 'Přidejte se.', '<p>Přidejte se.</p>', 'cs', 'Example s.r.o.'));

        self::assertCount(1, $transport->sent);
        $email = $transport->sent[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('noreply@example.com', $email->getFrom()[0]->getAddress());
        self::assertSame('Foresi', $email->getFrom()[0]->getName());
        self::assertSame('jana@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('Pozvánka', $email->getSubject());
        $text = (string) $email->getTextBody();
        self::assertStringContainsString('Přidejte se.', $text);
        self::assertStringContainsString('Odesláno systémem Foresi jménem Example s.r.o.', $text);
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('<p>Přidejte se.</p>', $html);
        self::assertStringContainsString('lang="cs"', $html);
        self::assertStringContainsString('Odesláno systémem Foresi jménem Example s.r.o.', $html);
    }

    public function testATextOnlyMailWithoutAnOrganisationIsSentByTheProduct(): void
    {
        self::bootKernel();
        $transport = new RecordingTransport();
        $handler = new DeliverSystemMailHandler(SystemMailSettings::through($transport, 'noreply@example.com'), self::getContainer()->get(Environment::class));

        $handler(new SystemMail('jana@example.com', 'Test', 'Hello.', null, 'en'));

        $email = $transport->sent[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertNull($email->getHtmlBody());
        self::assertStringEndsWith("Sent by Foresi\n", (string) $email->getTextBody());
    }

    public function testTheHeadersOfAListReachTheMail(): void
    {
        self::bootKernel();
        $transport = new RecordingTransport();
        $handler = new DeliverSystemMailHandler(SystemMailSettings::through($transport, 'noreply@example.com'), self::getContainer()->get(Environment::class));

        $handler(new SystemMail('jana@example.com', 'Digest', 'News.', null, 'en', null, ['List-Unsubscribe' => '<https://foresi.example.com/unsubscribe/x>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']));

        $email = $transport->sent[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('<https://foresi.example.com/unsubscribe/x>', $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
    }

    public function testAMailCarriesNoOtherHeader(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A system mail does not carry the header "Bcc".');

        new SystemMail('jana@example.com', 'Digest', 'News.', null, 'en', null, ['List-Unsubscribe' => '<x>', 'Bcc' => 'someone@example.com']);
    }

    public function testTheHandlerIsUnavailableWithoutAMailer(): void
    {
        self::bootKernel();
        $handler = new DeliverSystemMailHandler(new SystemMailSettings('', 'noreply@example.com'), self::getContainer()->get(Environment::class));

        $this->expectException(SystemMailUnavailable::class);
        $handler(new SystemMail('jana@example.com', 'Test', 'Hello.', null, 'en'));
    }

    public function testTheSettingsBuildTheTransportOfTheDsn(): void
    {
        self::assertSame('null://', (string) new SystemMailSettings('null://null', 'noreply@example.com')->transport());
    }

    public function testTheContainerSendsThroughTheSystemMailerNotTheFrameworkMailer(): void
    {
        self::bootKernel();

        // The test environment's SYSTEM_MAILER_DSN; the framework's mailer would read "(null://)".
        self::assertSame('null://', (string) self::getContainer()->get(SystemMailSettings::class)->transport());
    }

    public function testAMailLogsNoneOfItsContent(): void
    {
        $mail = new SystemMail('jana@example.com', 'Secret subject', 'Secret body', '<p>Secret</p>', 'en', 'Example Ltd');

        self::assertSame(['locale' => 'en'], $mail->jsonSerialize());
    }

    private function mailer(SystemMailSettings $settings): MessengerSystemMailer
    {
        return new MessengerSystemMailer($settings, self::getContainer()->get(MessageBusInterface::class));
    }
}
