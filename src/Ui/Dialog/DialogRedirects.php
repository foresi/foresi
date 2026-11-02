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
 * A dialog request answered with a redirect gets the target as JSON instead.
 * fetch() would follow a redirect itself and spend the flash message on a page
 * nobody sees; this way the controller decides what the browser loads next (the
 * page under the dialog again, or this target) and the message shows there. A
 * page request keeps its redirect. Every dialog answer varies on the header, so
 * a cache never serves the dialog for the page.
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
