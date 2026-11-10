<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Http;

use App\Invoicing\Application\InvoicingNotAllowed;
use App\Invoicing\Application\Query\PaymentsNotAllowed;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Application\UnknownPayment;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * What the query services of invoices and payments refuse, as the screens and the API
 * answer it: forbidden for one who may not work with them, not found for an invoice or a
 * payment the organisation does not have. Only on the routes of the invoicing screens and
 * of the API's invoices and payments, so the controllers carry no check of their own for
 * what they read.
 */
final readonly class InvoicingErrors
{
    public const array ROUTE_PREFIXES = ['app_invoice', 'app_advance', 'app_tax_documents', 'app_corrections', 'api_v1_invoice', 'api_v1_payment'];

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onException(ExceptionEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if (!\is_string($route) || !array_any(self::ROUTE_PREFIXES, static fn (string $prefix): bool => str_starts_with($route, $prefix))) {
            return;
        }
        $error = $event->getThrowable();
        if ($error instanceof InvoicingNotAllowed || $error instanceof PaymentsNotAllowed) {
            $event->setThrowable(new AccessDeniedHttpException($error->getMessage(), $error));
        } elseif ($error instanceof UnknownInvoice) {
            $event->setThrowable(new NotFoundHttpException('No such invoice.', $error));
        } elseif ($error instanceof UnknownPayment) {
            $event->setThrowable(new NotFoundHttpException('No such payment.', $error));
        }
    }
}
