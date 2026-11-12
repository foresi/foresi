<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Fixtures\Listeners;

use App\Shared\Application\Event\DomainEventListener;
use App\Shared\Application\Event\RecordedEvent;

/**
 * An async listener that remembers what it was given. It can be told to fail a
 * number of times first, so a test sees a delivery retried.
 */
final class RecordingListener implements DomainEventListener
{
    /** @var list<RecordedEvent> */
    public array $received = [];

    public function __construct(private readonly string $name, private int $failures = 0)
    {
    }

    public function listensTo(): string
    {
        return $this->name;
    }

    public function __invoke(RecordedEvent $event): void
    {
        $this->received[] = $event;
        if ($this->failures > 0) {
            --$this->failures;

            throw new \RuntimeException('Planned failure.');
        }
    }
}
