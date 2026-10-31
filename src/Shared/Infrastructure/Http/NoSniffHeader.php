<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tells browsers to trust the declared content type of every response and
 * never guess another one, so a downloaded CSV or a user's file name is
 * never run as a page or a script.
 */
final readonly class NoSniffHeader
{
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getResponse()->headers->set('X-Content-Type-Options', 'nosniff');
        }
    }
}
