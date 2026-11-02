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
 * turned off. No event dispatcher and no logger are given to the transport, so
 * nothing of the SMTP conversation is logged.
 */
final readonly class SymfonyMailGateway implements MailGateway
{
    private const float TIMEOUT_SECONDS = 30.0;

    public function __construct(private MailSecrets $secrets, private MailHostPolicy $hosts)
    {
    }

    public function send(MailMessage $message, FirmMailServer $server): void
    {
        if (!$this->hosts->allows($server->host())) {
            throw new MailRefused(true, 'The mail server address is not allowed.');
        }

        try {
            $email = $this->email($message);
            $transport = $this->transport($server);
            try {
                // The envelope comes from the headers: sender From, recipients To and Cc.
                $transport->send($email);
            } finally {
                $transport->stop();
            }
        } catch (TransportExceptionInterface|MimeException $failure) {
            throw self::refusal($failure);
        }
    }

    public function transport(FirmMailServer $server): EsmtpTransport
    {
        $tls = MailEncryption::Tls === $server->encryption();
        $transport = new EsmtpTransportFactory()->create(new Dsn(
            $tls ? 'smtps' : 'smtp',
            $server->host(),
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
            return new MailRefused(true, 'An address of the message is not valid.');
        }
        // Symfony keeps the SMTP code of an answer, and of a failed login, on the exception.
        $code = $failure->getCode();
        if ($code >= 500 && $code <= 599) {
            return new MailRefused(true, \sprintf('SMTP %d: the mail server refused the message.', $code));
        }
        if ($code >= 400 && $code <= 499) {
            return new MailRefused(false, \sprintf('SMTP %d: the mail server asks to try again later.', $code));
        }
        if ($failure instanceof UnexpectedResponseException) {
            return new MailRefused(false, 'The mail server gave an unexpected answer.');
        }

        return new MailRefused(false, 'The mail server could not be reached or the connection broke.');
    }
}
