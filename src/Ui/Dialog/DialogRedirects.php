<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Dialog;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A dialog that finished answers with where to go next instead of a redirect.
 * fetch() would follow a redirect itself and spend the flash message on a page
 * nobody sees; this way the browser loads that page and shows it. A page
 * request keeps its redirect. Both vary on the header, so no cache serves the
 * dialog for the page or the other way round.
 */
final readonly class DialogRedirects
{
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !DialogRequest::is($event->getRequest())) {
            return;
        }
        $response = $event->getResponse();
        if ($response->isRedirection() && null !== ($location = $response->headers->get('Location'))) {
            $response = new JsonResponse(['location' => $location]);
            $response->headers->set('Cache-Control', 'no-store');
            $event->setResponse($response);
        }
        $response->setVary([...$response->getVary(), DialogRequest::HEADER]);
    }
}
