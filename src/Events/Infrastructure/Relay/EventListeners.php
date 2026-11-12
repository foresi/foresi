<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Relay;

use App\Shared\Application\Event\DomainEventListener;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * The async listeners, each under its service id, and which of them listen to
 * an event name. The name of each is asked once, which builds every listener.
 */
final class EventListeners
{
    /** @var array<string, list<string>>|null the listener ids, by event name */
    private ?array $byName = null;

    /**
     * @param ServiceProviderInterface<DomainEventListener> $listeners
     */
    public function __construct(
        #[AutowireLocator('app.domain_event_listener')]
        private readonly ServiceProviderInterface $listeners,
    ) {
    }

    /**
     * @return list<string> the ids of the listeners of an event name
     */
    public function idsFor(string $name): array
    {
        if (null === $this->byName) {
            $this->byName = [];
            foreach ($this->all() as $id => $listener) {
                $this->byName[$listener->listensTo()][] = $id;
            }
        }

        return $this->byName[$name] ?? [];
    }

    /**
     * The listener of an id, or null when no listener has it any more.
     */
    public function get(string $id): ?DomainEventListener
    {
        return $this->listeners->has($id) ? $this->listeners->get($id) : null;
    }

    /**
     * @return array<string, DomainEventListener> by id
     */
    public function all(): array
    {
        $all = [];
        foreach (array_keys($this->listeners->getProvidedServices()) as $id) {
            $all[$id] = $this->listeners->get($id);
        }
        ksort($all, \SORT_STRING);

        return $all;
    }
}
