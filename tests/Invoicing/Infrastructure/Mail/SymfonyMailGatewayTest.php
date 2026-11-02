<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Mail;

use App\Invoicing\Application\MailMessage;
use App\Invoicing\Application\MailRefused;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\MailEncryption;
use App\Invoicing\Infrastructure\Mail\DnsMailHostPolicy;
use App\Invoicing\Infrastructure\Mail\SodiumMailSecrets;
use App\Invoicing\Infrastructure\Mail\SymfonyMailGateway;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Exception\RfcComplianceException;

final class SymfonyMailGatewayTest extends TestCase
{
    private const string USERNAME = 'mailbox-user@example.com';
    private const string PASSWORD = 'correct-horse-battery';
    private const string ADDRESS = '93.184.215.14';

    private SodiumMailSecrets $secrets;

    protected function setUp(): void
    {
        $this->secrets = new SodiumMailSecrets('k1:'.base64_encode(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    }

    public function testStarttlsTransportRequiresTls(): void
    {
        $transport = $this->gateway()->transport($this->server(MailEncryption::Starttls, 587), self::ADDRESS);

        self::assertTrue($transport->isTlsRequired());
        self::assertTrue($transport->isAutoTls());
        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertFalse($stream->isTLS(), 'STARTTLS upgrades a plain connection.');
        self::assertSame(self::ADDRESS, $stream->getHost(), 'The vetted address, not the name a second lookup could change.');
        self::assertSame(587, $stream->getPort());
        self::assertSame(self::USERNAME, $transport->getUsername());
        self::assertSame(self::PASSWORD, $transport->getPassword());
        self::assertSame(30.0, $stream->getTimeout());
        self::assertSame(['ssl' => ['peer_name' => 'smtp.example.com']], $stream->getStreamOptions(), 'The certificate and SNI use the name; verification keeps its defaults.');
    }

    public function testTlsTransportIsSmtps(): void
    {
        $transport = $this->gateway()->transport($this->server(MailEncryption::Tls, 465), self::ADDRESS);

        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertTrue($stream->isTLS());
        self::assertSame(self::ADDRESS, $stream->getHost());
        self::assertSame(465, $stream->getPort());
        self::assertSame(['ssl' => ['peer_name' => 'smtp.example.com']], $stream->getStreamOptions());
    }

    public function testAnIpv6AddressIsBracketedForTheSocket(): void
    {
        $server = FirmMailServer::define($this->firm(), '2606:2800:21f:cb07::1', 465, MailEncryption::Tls, self::USERNAME, $this->secrets->seal(self::PASSWORD), 'invoices@example.com', 'Example Firm');

        $stream = $this->gateway()->transport($server, '2606:2800:21f:cb07::1')->getStream();

        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame('[2606:2800:21f:cb07::1]', $stream->getHost());
        self::assertSame(['ssl' => ['peer_name' => '2606:2800:21f:cb07::1']], $stream->getStreamOptions());
    }

    public function testAPasswordTheKeysCannotOpenIsAPermanentSettingsProblem(): void
    {
        $server = FirmMailServer::define($this->firm(), 'smtp.example.com', 587, MailEncryption::Starttls, self::USERNAME, 'gone:'.base64_encode(str_repeat('x', 48)), 'invoices@example.com', 'Example Firm');

        try {
            $this->gateway()->send($this->message(), $server);
            self::fail('A password that cannot be opened cannot be sent with.');
        } catch (MailRefused $refused) {
            self::assertTrue($refused->permanent);
            self::assertTrue($refused->settingsProblem);
            self::assertNull($refused->getPrevious());
        }
    }

    public function testANameThatDoesNotResolveIsRetried(): void
    {
        $gateway = new SymfonyMailGateway($this->secrets, new DnsMailHostPolicy(false, static fn (): array => []));

        try {
            $gateway->send($this->message(), $this->server(MailEncryption::Starttls, 587));
            self::fail('Nothing to connect to.');
        } catch (MailRefused $refused) {
            self::assertFalse($refused->permanent);
        }
    }

    public function testAPasswordWithDsnCharactersNeedsNoEncoding(): void
    {
        $server = FirmMailServer::define($this->firm(), 'smtp.example.com', 587, MailEncryption::Starttls, 'user@example.com', $this->secrets->seal('p@ss:w/rd?#%'), 'invoices@example.com', 'Example Firm');

        self::assertSame('p@ss:w/rd?#%', $this->gateway()->transport($server, self::ADDRESS)->getPassword());
    }

    public function testBuildsTheMessage(): void
    {
        $email = $this->gateway()->email(new MailMessage(
            'invoices@example.com',
            'Example Firm',
            'office@example.com',
            ['customer@example.com', 'accounts@example.com'],
            ['copy@example.com'],
            'Invoice 2026-0001',
            "Hello,\nthe invoice is at the link.",
            '<p>Hello,</p>',
            '<0195f0a2-0000-7000-8000-000000000001@example.com>',
        ));

        self::assertSame('"Example Firm" <invoices@example.com>', $email->getFrom()[0]->toString());
        self::assertSame(['office@example.com'], array_map(static fn (\Symfony\Component\Mime\Address $a): string => $a->getAddress(), $email->getReplyTo()));
        self::assertSame(['customer@example.com', 'accounts@example.com'], array_map(static fn (\Symfony\Component\Mime\Address $a): string => $a->getAddress(), $email->getTo()));
        self::assertSame(['copy@example.com'], array_map(static fn (\Symfony\Component\Mime\Address $a): string => $a->getAddress(), $email->getCc()));
        self::assertSame([], $email->getBcc());
        self::assertSame('Invoice 2026-0001', $email->getSubject());
        self::assertSame("Hello,\nthe invoice is at the link.", $email->getTextBody());
        self::assertSame('<p>Hello,</p>', $email->getHtmlBody());
        self::assertSame('<0195f0a2-0000-7000-8000-000000000001@example.com>', $email->getHeaders()->get('Message-ID')?->getBodyAsString());
        self::assertSame([], $email->getAttachments());
    }

    public function testNoReplyToWithoutOne(): void
    {
        $email = $this->gateway()->email(new MailMessage('invoices@example.com', 'Example Firm', null, ['customer@example.com'], [], 'Subject', 'Text', '<p>Text</p>', '<1@example.com>'));

        self::assertSame([], $email->getReplyTo());
    }

    public function testARefusedHostIsNeverContacted(): void
    {
        $gateway = new SymfonyMailGateway($this->secrets, new DnsMailHostPolicy(false, static fn (): array => ['10.0.0.1']));

        try {
            $gateway->send($this->message(), $this->server(MailEncryption::Starttls, 587));
            self::fail('A refused host must not be contacted.');
        } catch (MailRefused $refused) {
            self::assertTrue($refused->permanent);
            self::assertTrue($refused->settingsProblem);
            self::assertSame('The mail server address is not allowed.', $refused->safeReason);
            self::assertNull($refused->getPrevious());
        }
    }

    /**
     * @param \Closure(): \Throwable $failure
     */
    #[DataProvider('failures')]
    public function testA5xxIsPermanentAnd4xxIsNot(\Closure $failure, bool $permanent, string $reason, bool $settings = false): void
    {
        $refused = SymfonyMailGateway::refusal($failure());

        self::assertSame($permanent, $refused->permanent);
        self::assertSame($settings, $refused->settingsProblem);
        self::assertSame($reason, $refused->safeReason);
        self::assertSame($reason, $refused->getMessage());
        self::assertNull($refused->getPrevious(), 'The server text would travel with the exception.');
    }

    /**
     * @return iterable<string, array{0: \Closure(): \Throwable, 1: bool, 2: string, 3?: bool}>
     */
    public static function failures(): iterable
    {
        yield 'mailbox unknown' => [static fn (): \Throwable => new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 5.1.1 No such user".', 550), true, 'SMTP 550: the mail server refused the message.'];
        yield 'authentication failed' => [static fn (): \Throwable => new UnexpectedResponseException('Expected response code "235" but got code "535".', 535), true, 'SMTP 535: the mail server refused the login.', true];
        yield 'login failed after every authenticator' => [static fn (): \Throwable => new TransportException('Failed to authenticate on SMTP server with username "x" using the following authenticators: "LOGIN".', 535), true, 'SMTP 535: the mail server refused the login.', true];
        yield 'authentication required' => [static fn (): \Throwable => new UnexpectedResponseException('Expected response code "250" but got code "530".', 530), true, 'SMTP 530: the mail server refused the login.', true];
        yield 'no STARTTLS' => [static fn (): \Throwable => new TransportException('TLS required but neither TLS or STARTTLS are in use.'), false, 'The secure connection to the mail server failed: check the encryption, the port and the host name.', true];
        yield 'STARTTLS failed' => [static fn (): \Throwable => new TransportException('Unable to connect with STARTTLS.'), false, 'The secure connection to the mail server failed: check the encryption, the port and the host name.', true];
        yield 'certificate' => [static fn (): \Throwable => new TransportException('Connection could not be established with host "ssl://93.184.215.14:465": stream_socket_client(): SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed'), false, 'The secure connection to the mail server failed: check the encryption, the port and the host name.', true];
        yield 'greylisted' => [static fn (): \Throwable => new UnexpectedResponseException('Expected response code "250" but got code "451".', 451), false, 'SMTP 451: the mail server asks to try again later.'];
        yield 'busy' => [static fn (): \Throwable => new UnexpectedResponseException('Expected response code "220" but got code "421".', 421), false, 'SMTP 421: the mail server asks to try again later.'];
        yield 'no code' => [static fn (): \Throwable => new UnexpectedResponseException('Expected response code "250" but got empty code.', 0), false, 'The mail server gave an unexpected answer.'];
        yield 'a code out of range' => [static fn (): \Throwable => new UnexpectedResponseException('Odd.', 354), false, 'The mail server gave an unexpected answer.'];
        yield 'unreachable' => [static fn (): \Throwable => new TransportException('Connection could not be established with host "ssl://smtp.example.com:465".'), false, 'The mail server could not be reached or the connection broke.'];
        yield 'invalid address' => [static fn (): \Throwable => new RfcComplianceException('Email "x" does not comply with addr-spec of RFC 2822.'), true, 'An address of the message is not valid.'];
    }

    public function testASafeReasonNeverHoldsThePasswordOrUsername(): void
    {
        $leaky = [
            new UnexpectedResponseException(\sprintf('Expected response code "235" but got code "535", with message "535 %s %s".', self::USERNAME, self::PASSWORD), 535),
            new TransportException(\sprintf('Failed to authenticate on SMTP server with username "%s" using the following authenticators: "LOGIN". Password %s', self::USERNAME, self::PASSWORD)),
            new UnexpectedResponseException(self::PASSWORD, 451),
        ];

        foreach ($leaky as $failure) {
            $refused = SymfonyMailGateway::refusal($failure);
            foreach ([$refused->safeReason, $refused->getMessage(), (string) $refused] as $text) {
                self::assertStringNotContainsString(self::PASSWORD, $text);
                self::assertStringNotContainsString(self::USERNAME, $text);
                self::assertStringNotContainsString('mailbox-user', $text);
            }
        }
    }

    private function gateway(): SymfonyMailGateway
    {
        return new SymfonyMailGateway($this->secrets, new DnsMailHostPolicy(false, static fn (): array => [self::ADDRESS]));
    }

    private function server(MailEncryption $encryption, int $port): FirmMailServer
    {
        return FirmMailServer::define($this->firm(), 'smtp.example.com', $port, $encryption, self::USERNAME, $this->secrets->seal(self::PASSWORD), 'invoices@example.com', 'Example Firm');
    }

    private function message(): MailMessage
    {
        return new MailMessage('invoices@example.com', 'Example Firm', null, ['customer@example.com'], [], 'Subject', 'Text', '<p>Text</p>', '<1@example.com>');
    }

    private function firm(): LegalEntity
    {
        return new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '', []);
    }
}
