<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Plays the worker for the in-memory transport: handles every message sent,
 * including the ones the handlers send, until the queue is empty. It remembers
 * what it handled, so a test can deliver a message a second time as a crashed
 * worker's redelivery would.
 */
final class RunMessages
{
    /** @var list<Envelope> */
    private array $handled = [];

    public function __construct(
        private readonly InMemoryTransport $transport,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Handles the waiting messages, and the ones they lead to.
     *
     * @param int|null                      $limit stop after this many messages
     * @param (callable(object): bool)|null $only  handle only the messages it accepts; the others stay queued
     *
     * @return int the number of messages handled
     */
    public function run(?int $limit = null, ?callable $only = null): int
    {
        $count = 0;
        while (null === $limit || $count < $limit) {
            $next = null;
            foreach ($this->transport->get() as $envelope) {
                if (null === $only || $only($envelope->getMessage())) {
                    $next = $envelope;
                    break;
                }
            }
            if (!$next instanceof Envelope) {
                break;
            }
            $this->transport->ack($next);
            $this->deliver($next);
            $this->handled[] = $next;
            ++$count;
        }

        return $count;
    }

    /** Delivers a message again, as after a worker died before acknowledging it. */
    public function redeliver(Envelope $envelope): void
    {
        $this->deliver($envelope);
    }

    /**
     * @return list<Envelope>
     */
    public function handled(): array
    {
        return $this->handled;
    }

    public function last(): Envelope
    {
        return $this->handled[\count($this->handled) - 1] ?? throw new \LogicException('Nothing was handled yet.');
    }

    /**
     * @return list<object> the messages waiting in the queue
     */
    public function pending(): array
    {
        return array_values(array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), [...$this->transport->get()]));
    }

    private function deliver(Envelope $envelope): void
    {
        $this->bus->dispatch($envelope->withoutAll(ReceivedStamp::class)->with(new ReceivedStamp('async')));
    }
}
