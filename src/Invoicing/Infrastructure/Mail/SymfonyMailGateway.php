<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Mail;

use App\Invoicing\Application\MailGateway;
use App\Invoicing\Application\MailHostPolicy;
use App\Invoicing\Application\MailMessage;
use App\Invoicing\Application\MailRefused;
use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\MailSecretsUnavailable;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\MailEncryption;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeException;

/**
 * Sends through the firm's own server, one connection per message. The transport
 * is built from a Dsn object, never a URL string, so a password needs no encoding;
 * STARTTLS is required, or TLS from the start, and peer verification is never
 * turned off. The host is resolved once, by the host policy, and the connection
 * goes to that address. No event dispatcher and no logger are given to the transport, so
 * nothing of the SMTP conversation is logged.
 */
final readonly class SymfonyMailGateway implements MailGateway
{
    private const float TIMEOUT_SECONDS = 30.0;

    /** Authentication required, too weak, or failed. */
    private const array LOGIN_CODES = [530, 534, 535, 538];

    /** What Symfony and PHP's OpenSSL say when STARTTLS, the handshake or the certificate fails. */
    private const string SECURE_CONNECTION_FAILURE = '/STARTTLS|TLS required|certificate|SSL operation failed|crypto|handshake/i';

    public function __construct(private MailSecrets $secrets, private MailHostPolicy $hosts)
    {
    }

    public function send(MailMessage $message, FirmMailServer $server): void
    {
        $address = $this->hosts->vet($server->host());

        try {
            $email = $this->email($message);
            $transport = $this->transport($server, $address);
            try {
                // The envelope comes from the headers: sender From, recipients To and Cc.
                $transport->send($email);
            } finally {
                $transport->stop();
            }
        } catch (MailSecretsUnavailable) {
            throw new MailRefused(true, 'The mail server password cannot be opened with the configured keys: enter it again in the e-mail settings.', true, MailRefused::PASSWORD_UNREADABLE);
        } catch (TransportExceptionInterface|MimeException $failure) {
            throw self::refusal($failure);
        }
    }

    /**
     * Connects to the vetted address, never to the name again, while TLS checks
     * the certificate against the name: peer_name is also what PHP sends as SNI.
     *
     * @param string $address the address the host policy vetted
     */
    public function transport(FirmMailServer $server, string $address): EsmtpTransport
    {
        $tls = MailEncryption::Tls === $server->encryption();
        $transport = new EsmtpTransportFactory()->create(new Dsn(
            $tls ? 'smtps' : 'smtp',
            str_contains($address, ':') ? '['.$address.']' : $address,
            $server->username(),
            $this->secrets->open($server->sealedSecret()),
            $server->port(),
            $tls ? [] : ['require_tls' => 'true'],
        ));
        if (!$transport instanceof EsmtpTransport) {
            throw new \LogicException('The SMTP factory builds an ESMTP transport.');
        }
        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(self::TIMEOUT_SECONDS);
            $options = $stream->getStreamOptions();
            $ssl = \is_array($options['ssl'] ?? null) ? $options['ssl'] : [];
            $ssl['peer_name'] = trim($server->host(), '[]');
            $options['ssl'] = $ssl;
            $stream->setStreamOptions($options);
        }

        return $transport;
    }

    public function email(MailMessage $message): Email
    {
        $email = new Email()
            ->from(new Address($message->from, $message->fromName))
            ->to(...$message->to)
            ->subject($message->subject)
            ->text($message->text)
            ->html($message->html);
        if ([] !== $message->cc) {
            $email->cc(...$message->cc);
        }
        if (null !== $message->replyTo) {
            $email->replyTo($message->replyTo);
        }
        $email->getHeaders()->addIdHeader('Message-ID', substr($message->messageId, 1, -1));

        return $email;
    }

    /**
     * Maps a failure to a reason that is safe to keep: a 5xx answer, such as a
     * refused login, is permanent, and so is a message that cannot be built;
     * anything else is worth another try.
     */
    public static function refusal(\Throwable $failure): MailRefused
    {
        if ($failure instanceof MimeException) {
            return new MailRefused(true, 'An address of the message is not valid.', reasonCode: MailRefused::BAD_ADDRESS);
        }
        // Symfony keeps the SMTP code of an answer, and of a failed login, on the exception.
        $code = $failure->getCode();
        if (\in_array($code, self::LOGIN_CODES, true)) {
            return new MailRefused(true, \sprintf('SMTP %d: the mail server refused the login.', $code), true, MailRefused::LOGIN_REFUSED, $code);
        }
        if ($code >= 500 && $code <= 599) {
            return new MailRefused(true, \sprintf('SMTP %d: the mail server refused the message.', $code), reasonCode: MailRefused::MESSAGE_REFUSED, smtpCode: $code);
        }
        if ($code >= 400 && $code <= 499) {
            return new MailRefused(false, \sprintf('SMTP %d: the mail server asks to try again later.', $code), reasonCode: MailRefused::TRY_LATER, smtpCode: $code);
        }
        if ($failure instanceof UnexpectedResponseException) {
            return new MailRefused(false, 'The mail server gave an unexpected answer.', reasonCode: MailRefused::UNEXPECTED_ANSWER);
        }
        // Read only to sort the failure: the text itself is never kept.
        if (1 === preg_match(self::SECURE_CONNECTION_FAILURE, $failure->getMessage())) {
            return new MailRefused(false, 'The secure connection to the mail server failed: check the encryption, the port and the host name.', true, MailRefused::SECURE_CONNECTION);
        }

        return new MailRefused(false, 'The mail server could not be reached or the connection broke.', reasonCode: MailRefused::UNREACHABLE);
    }
}
