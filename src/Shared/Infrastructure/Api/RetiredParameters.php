<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Api;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Parameters the API and MCP took once and no longer take. A client that still
 * sends one is refused, so it does not read more than it asked for: REST with
 * 400 invalid_parameter naming the parameter. An MCP server refuses an argument
 * its tool's schema does not list; a call that reaches the tool anyway is
 * refused with invalid_argument. A write refuses them as fields that are not
 * part of its operation.
 */
final readonly class RetiredParameters
{
    /** The name as the client sent it, and what to do instead. */
    public const array RETIRED = [
        'firm_id' => 'firm_id is no longer supported: an instance holds one firm, and every read and write is of that firm. Leave it out.',
    ];

    /**
     * The first retired one among the names, with its message.
     *
     * @param iterable<array-key, mixed> $names
     *
     * @return array{string, string}|null
     */
    public static function first(iterable $names): ?array
    {
        foreach ($names as $name) {
            if (\is_string($name) && isset(self::RETIRED[$name])) {
                return [$name, self::RETIRED[$name]];
            }
        }

        return null;
    }

    /**
     * After the router and the firewall, so a request without a valid token is still 401.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), ApiErrors::PATH_PREFIX)) {
            return;
        }
        $retired = self::first(array_keys($request->query->all()));
        if (null !== $retired) {
            throw new InvalidParameter($retired[0], $retired[1]);
        }
    }
}
