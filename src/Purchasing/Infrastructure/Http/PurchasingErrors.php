<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Http;

use Foresi\Party\Application\NoFirmYet;
use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\UnknownReceivedDocument;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * What the use cases and query services of received documents refuse, as the
 * screens answer it: forbidden for one who may not work with them, not found
 * for a firm or a document the organisation does not have. Only on the routes
 * of the module's screens and of its REST reads, so the controllers carry no
 * check of their own.
 */
final readonly class PurchasingErrors
{
    public const array ROUTE_PREFIXES = ['app_purchasing', 'api_v1_received_document'];

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onException(ExceptionEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if (!\is_string($route) || !array_any(self::ROUTE_PREFIXES, static fn (string $prefix): bool => str_starts_with($route, $prefix))) {
            return;
        }
        $error = $event->getThrowable();
        if ($error instanceof PurchasingNotAllowed) {
            $event->setThrowable(new AccessDeniedHttpException($error->getMessage(), $error));
        } elseif ($error instanceof UnknownReceivedDocument || $error instanceof NoFirmYet) {
            $event->setThrowable(new NotFoundHttpException('No such received document, or the instance has no firm yet.', $error));
        }
    }
}
