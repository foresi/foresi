<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * A message that cannot be decoded reaches the failed transport instead of
 * being thrown away. On its own, the Doctrine receiver deletes such a message
 * and the exception stops the worker.
 */
final readonly class KeepUndecodableMessages implements SerializerInterface
{
    private PhpSerializer $inner;

    public function __construct()
    {
        $this->inner = new PhpSerializer();
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        try {
            return $this->inner->decode($encodedEnvelope);
        } catch (MessageDecodingFailedException $failure) {
            return new Envelope(new UndecodableMessage($encodedEnvelope, $failure->getMessage()));
        }
    }

    public function encode(Envelope $envelope): array
    {
        return $this->inner->encode($envelope);
    }
}
