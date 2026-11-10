<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Http;

use App\Invoicing\Application\InvoicingNotAllowed;
use App\Invoicing\Application\UnknownInvoice;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * What the query services of invoices refuse, as the screens answer it: forbidden for
 * one who may not work with invoices, not found for an invoice the organisation does not
 * have. Only on the routes of the invoicing screens, so the controllers carry no check of
 * their own for what they read.
 */
final readonly class InvoicingErrors
{
    public const array ROUTE_PREFIXES = ['app_invoice', 'app_advance', 'app_tax_documents', 'app_corrections'];

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onException(ExceptionEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if (!\is_string($route) || !array_any(self::ROUTE_PREFIXES, static fn (string $prefix): bool => str_starts_with($route, $prefix))) {
            return;
        }
        $error = $event->getThrowable();
        if ($error instanceof InvoicingNotAllowed) {
            $event->setThrowable(new AccessDeniedHttpException($error->getMessage(), $error));
        } elseif ($error instanceof UnknownInvoice) {
            $event->setThrowable(new NotFoundHttpException('No such invoice.', $error));
        }
    }
}
