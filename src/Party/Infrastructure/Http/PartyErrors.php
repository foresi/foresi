<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Http;

use App\Party\Application\PartyNotAllowed;
use App\Party\Application\UnknownParty;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * What the query services of the address book refuse, as the screens and the
 * API answer it: forbidden for one who may not read it, not found for a contact
 * the organisation does not have. Only on the contact routes of the screens and
 * the API, so the controllers carry no check of their own.
 */
final readonly class PartyErrors
{
    public const array ROUTE_PREFIXES = ['app_contact', 'api_v1_contact'];

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onException(ExceptionEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if (!\is_string($route) || !array_any(self::ROUTE_PREFIXES, static fn (string $prefix): bool => str_starts_with($route, $prefix))) {
            return;
        }
        $error = $event->getThrowable();
        if ($error instanceof PartyNotAllowed) {
            $event->setThrowable(new AccessDeniedHttpException($error->getMessage(), $error));
        } elseif ($error instanceof UnknownParty) {
            $event->setThrowable(new NotFoundHttpException('No such contact.', $error));
        }
    }
}
