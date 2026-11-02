<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Mail;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Symfony's socket stream with an overall deadline for the whole conversation,
 * counted from the connection. Its own timeout bounds each read, so a slow
 * server that answers line by line, or sends a line a byte at a time, could
 * keep it going for ever. Here every wait for the server is cut to the time
 * left: the stream reads in chunks, never a whole line in one call, and sets
 * the socket timeout before each read and each write, so no wait ends after
 * the deadline. Past it, the next read or write fails as a timeout does.
 *
 * Connecting, opening and closing the socket and STARTTLS stay with Symfony's
 * stream. The TLS handshake is one call into OpenSSL that starts with the time
 * left as its timeout and is checked against the deadline when it returns.
 * Writes wait only when the kernel's send buffer is full, which the short
 * messages sent here, with a link and no attachment, do not fill.
 *
 * Nothing of the conversation is kept for debugging: it holds the login.
 */
final class DeadlineSocketStream extends AbstractStream
{
    private const int CHUNK = 8192;
    /** RFC 5321 allows 512 octets per reply line; anything far longer is not SMTP. */
    private const int MAX_LINE = 65536;
    private const string PAST_DEADLINE = 'The conversation with the mail server ran past its deadline.';

    private readonly SocketStream $socket;
    private float $endsAt = \INF;
    private string $buffer = '';

    /**
     * @param float $deadlineSeconds how long the whole conversation may take, from the connection on
     */
    public function __construct(private readonly float $deadlineSeconds)
    {
        $this->socket = new SocketStream();
    }

    public function initialize(): void
    {
        $this->endsAt = $this->now() + $this->deadlineSeconds;
        $this->buffer = '';
        // Connecting may not take longer than the whole conversation.
        $this->socket->setTimeout(min($this->socket->getTimeout(), $this->deadlineSeconds));
        $this->socket->initialize();
        $this->stream = $this->socket->stream;
        $this->in = $this->stream;
        $this->out = $this->stream;
    }

    public function startTLS(): bool
    {
        if ('' !== $this->buffer) {
            // Anything the server sent before the handshake did not come over TLS.
            throw new TransportException('Unable to connect with STARTTLS: the server sent data before the handshake.');
        }
        $this->waitAtMostTheTimeLeft();
        $started = $this->socket->startTLS();
        $this->waitAtMostTheTimeLeft();

        return $started;
    }

    public function terminate(): void
    {
        $this->socket->terminate();
        $this->buffer = '';
        parent::terminate();
    }

    public function readLine(): string
    {
        $out = $this->out;
        if (null === $out) {
            return '';
        }
        while (true) {
            $end = strpos($this->buffer, "\n");
            if (false !== $end) {
                break;
            }
            if (feof($out)) {
                if ('' === $this->buffer) {
                    return '';
                }

                throw new TransportException(\sprintf('Connection to "%s" has been closed unexpectedly.', $this->getReadConnectionDescription()));
            }
            if (\strlen($this->buffer) > self::MAX_LINE) {
                throw new TransportException('The mail server sent a line far longer than SMTP allows.');
            }
            $this->waitAtMostTheTimeLeft();
            // One read of what has arrived, not a whole line: each wait is cut to the time left.
            $chunk = @fread($out, self::CHUNK);
            if (false === $chunk || '' === $chunk) {
                if (stream_get_meta_data($out)['timed_out']) {
                    throw new TransportException($this->now() >= $this->endsAt ? self::PAST_DEADLINE : \sprintf('Connection to "%s" timed out.', $this->getReadConnectionDescription()));
                }
                if (feof($out)) {
                    continue;
                }
                if (false === $chunk) {
                    throw new TransportException(\sprintf('Unable to read from connection to "%s".', $this->getReadConnectionDescription()));
                }

                continue;
            }
            $this->buffer .= $chunk;
        }
        $line = substr($this->buffer, 0, $end + 1);
        $this->buffer = substr($this->buffer, $end + 1);

        return $line;
    }

    public function write(string $bytes, bool $debug = true): void
    {
        $in = $this->in ?? throw new TransportException('Unable to write bytes on the wire.');
        $length = \strlen($bytes);
        for ($written = 0; $written < $length; $written += $done) {
            $this->waitAtMostTheTimeLeft();
            $done = @fwrite($in, substr($bytes, $written, self::CHUNK));
            if (false === $done || 0 === $done) {
                throw new TransportException($this->now() >= $this->endsAt ? self::PAST_DEADLINE : 'Unable to write bytes on the wire.');
            }
        }
    }

    public function hasPendingData(): bool
    {
        return '' !== $this->buffer || parent::hasPendingData();
    }

    public function getDebug(): string
    {
        return '';
    }

    public function setTimeout(float $timeout): static
    {
        $this->socket->setTimeout($timeout);

        return $this;
    }

    public function getTimeout(): float
    {
        return $this->socket->getTimeout();
    }

    public function deadline(): float
    {
        return $this->deadlineSeconds;
    }

    public function setHost(string $host): static
    {
        $this->socket->setHost($host);

        return $this;
    }

    public function getHost(): string
    {
        return $this->socket->getHost();
    }

    public function setPort(int $port): static
    {
        $this->socket->setPort($port);

        return $this;
    }

    public function getPort(): int
    {
        return $this->socket->getPort();
    }

    public function disableTls(): static
    {
        $this->socket->disableTls();

        return $this;
    }

    public function isTLS(): bool
    {
        return $this->socket->isTLS();
    }

    /**
     * @param array<mixed> $options
     */
    public function setStreamOptions(array $options): static
    {
        $this->socket->setStreamOptions($options);

        return $this;
    }

    /**
     * @return array<mixed>
     */
    public function getStreamOptions(): array
    {
        return $this->socket->getStreamOptions();
    }

    protected function getReadConnectionDescription(): string
    {
        return $this->socket->getHost().':'.$this->socket->getPort();
    }

    /**
     * Cuts the socket's timeout to the time left, or fails once none is left.
     */
    private function waitAtMostTheTimeLeft(): void
    {
        $left = $this->endsAt - $this->now();
        if ($left <= 0.0) {
            throw new TransportException(self::PAST_DEADLINE);
        }
        if (null === $this->stream) {
            return;
        }
        $wait = min($left, $this->socket->getTimeout());
        stream_set_timeout($this->stream, (int) $wait, (int) (($wait - (int) $wait) * 1_000_000));
    }

    private function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
