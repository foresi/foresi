<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Api;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The answers that hold a new address, shown once, are never kept by a cache or
 * a proxy: Cache-Control: no-store, private.
 */
final readonly class NoStoreOnIssuedAddresses
{
    private const array ROUTES = ['api_v1_operation_mail.addresses.create', 'api_v1_operation_mail.addresses.replace'];

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !\in_array($event->getRequest()->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }
        $event->getResponse()->headers->set('Cache-Control', 'no-store, private');
    }
}
