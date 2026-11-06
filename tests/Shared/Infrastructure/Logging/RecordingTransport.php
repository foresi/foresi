<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Logging;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A mail transport that keeps what it was given, or refuses everything.
 */
final class RecordingTransport implements TransportInterface
{
    /** @var list<RawMessage> */
    public array $sent = [];

    public function __construct(private readonly bool $fail = false)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->fail) {
            throw new TransportException('Connection refused');
        }
        $this->sent[] = $message;

        return null;
    }

    public function __toString(): string
    {
        return 'recording://';
    }
}
