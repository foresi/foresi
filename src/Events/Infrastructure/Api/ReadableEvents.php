<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Api;

use App\Events\Application\Query\EventFeed;
use App\Events\Infrastructure\Catalogue\EventCatalogue;
use App\Events\Infrastructure\Catalogue\EventType;
use App\Shared\Application\Event\EventReadAccess;
use App\Shared\Infrastructure\Api\InvalidParameter;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The feed as the caller may read it, for REST and MCP alike: which event types
 * the caller asked for, which of them the caller may read, and one page of them.
 * A module that has no access service is not readable.
 */
final readonly class ReadableEvents
{
    /**
     * @param iterable<EventReadAccess> $access
     */
    public function __construct(
        private EventFeed $feed,
        private EventCatalogue $catalogue,
        private EventView $view,
        #[AutowireIterator('app.event_read_access')]
        private iterable $access,
    ) {
    }

    /**
     * @param list<string> $patterns event names and "module.*"; none means every readable module
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     *
     * @throws InvalidParameter          for a cursor this API did not give, or a type that does not exist
     * @throws AccessDeniedHttpException for a type of a module the caller may not read
     */
    public function page(array $patterns, int $limit, ?string $cursor): array
    {
        $after = PositionCursor::decode($cursor);
        $readable = $this->readableModules();
        if ([] === $patterns) {
            $patterns = array_map(static fn (string $module): string => $module.'.*', $readable);
        }
        $names = $this->names($patterns, $readable);

        $slice = $this->feed->slice($after, $names, $limit);

        return [
            'items' => array_map($this->view->of(...), $slice->rows),
            // Always the place to read on from: after the last event of the page, or where the caller was when nothing is new.
            'next_cursor' => PositionCursor::next($slice->lastPosition() ?? $after),
        ];
    }

    /**
     * The event types of the modules the caller may read.
     *
     * @return list<EventType>
     */
    public function types(): array
    {
        $readable = $this->readableModules();

        return array_values(array_filter($this->catalogue->all(), static fn (EventType $type): bool => \in_array($type->module->value, $readable, true)));
    }

    /**
     * @param list<string> $patterns
     * @param list<string> $readable
     *
     * @return list<string>
     */
    private function names(array $patterns, array $readable): array
    {
        $names = [];
        foreach ($patterns as $pattern) {
            $matching = $this->catalogue->matching([$pattern]);
            if ([] === $matching) {
                throw new InvalidParameter('types', \sprintf('The event type "%s" does not exist. Use an event name or "module.*".', $pattern));
            }
            foreach ($matching as $name) {
                if (!\in_array(explode('.', $name, 2)[0], $readable, true)) {
                    throw new AccessDeniedHttpException(\sprintf('The events of "%s" are not yours to read.', explode('.', $name, 2)[0]));
                }
                $names[$name] = true;
            }
        }

        return array_map(strval(...), array_keys($names));
    }

    /**
     * @return list<string>
     */
    private function readableModules(): array
    {
        $modules = [];
        foreach ($this->access as $access) {
            if ($access->mayRead()) {
                $modules[] = $access->module();
            }
        }
        sort($modules, \SORT_STRING);

        return array_values(array_unique($modules));
    }
}
