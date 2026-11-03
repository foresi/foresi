<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Mail;

use App\Invoicing\Application\MailRefused;
use App\Invoicing\Infrastructure\Mail\DeadlineSocketStream;
use App\Invoicing\Infrastructure\Mail\RecipientNamingEsmtpTransport;
use App\Invoicing\Infrastructure\Mail\RecipientRefused;
use App\Invoicing\Infrastructure\Mail\SymfonyMailGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Process;

final class RecipientNamingEsmtpTransportTest extends TestCase
{
    public function testARecipientTheServerRefusesIsNamed(): void
    {
        // A server that knows the customer and not the Cc.
        $server = new Process([\PHP_BINARY, '-r', <<<'PHP'
            $server = stream_socket_server('tcp://127.0.0.1:0');
            echo stream_socket_get_name($server, false), "\n";
            $peer = stream_socket_accept($server, 10);
            fwrite($peer, "220 smtp.example.com ESMTP\r\n");
            while (false !== ($line = fgets($peer))) {
                fwrite($peer, match (true) {
                    str_starts_with($line, 'EHLO') => "250 smtp.example.com\r\n",
                    str_starts_with($line, 'RCPT TO:<unknown@example.com>') => "550 5.1.1 No such user here\r\n",
                    str_starts_with($line, 'QUIT') => "221 bye\r\n",
                    default => "250 ok\r\n",
                });
            }
            PHP]);
        $server->start();
        $server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "\n"));
        [$host, $port] = explode(':', trim($server->getOutput()));
        $transport = new RecipientNamingEsmtpTransport($host, (int) $port, false, stream: new DeadlineSocketStream(10.0));
        $transport->setAutoTls(false);

        try {
            $transport->send(new Email()->from('invoices@example.com')->to('billing@example.com')->cc('unknown@example.com')->subject('Invoice')->text('Text'));
            self::fail('The server refused a recipient.');
        } catch (RecipientRefused $refused) {
            self::assertSame('unknown@example.com', $refused->recipient);
            self::assertSame(550, $refused->getCode());
        } finally {
            $transport->stop();
            $server->stop(0);
        }
    }

    public function testTheReasonNamesTheRefusedRecipientButNoServerText(): void
    {
        $refused = SymfonyMailGateway::refusal(new RecipientRefused('unknown@example.com', new UnexpectedResponseException('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 No such user mailbox-user".', 550)));

        self::assertTrue($refused->permanent);
        self::assertFalse($refused->settingsProblem);
        self::assertSame(MailRefused::RECIPIENT_REFUSED, $refused->reasonCode);
        self::assertSame(550, $refused->smtpCode);
        self::assertSame('SMTP 550: the mail server refused the recipient <unknown@example.com>.', $refused->safeReason);
        self::assertNull($refused->getPrevious());
    }

    public function testATemporaryRefusalOfARecipientStaysTemporary(): void
    {
        $refused = SymfonyMailGateway::refusal(new RecipientRefused('unknown@example.com', new UnexpectedResponseException('Expected response code "250/251/252" but got code "450".', 450)));

        self::assertFalse($refused->permanent);
        self::assertSame(MailRefused::TRY_LATER, $refused->reasonCode);
    }
}
