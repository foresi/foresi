<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Http;

use App\Party\Application\UnknownLegalEntity;
use App\Purchasing\Application\PurchasingNotAllowed;
use App\Purchasing\Application\UnknownReceivedDocument;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * What the use cases and query services of received documents refuse, as the
 * screens answer it: forbidden for one who may not work with them, not found
 * for a firm or a document the organisation does not have. Only on the routes
 * of the module, so the controllers carry no check of their own.
 */
final readonly class PurchasingErrors
{
    public const string ROUTE_PREFIX = 'app_purchasing';

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onException(ExceptionEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if (!\is_string($route) || !str_starts_with($route, self::ROUTE_PREFIX)) {
            return;
        }
        $error = $event->getThrowable();
        if ($error instanceof PurchasingNotAllowed) {
            $event->setThrowable(new AccessDeniedHttpException($error->getMessage(), $error));
        } elseif ($error instanceof UnknownReceivedDocument || $error instanceof UnknownLegalEntity) {
            $event->setThrowable(new NotFoundHttpException('No such received document or firm.', $error));
        }
    }
}
