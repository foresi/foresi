<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Api;

use App\Shared\Infrastructure\Api\InvalidParameter;
use App\Shared\Infrastructure\Api\ProblemResponse;
use App\Webhooks\Application\DeliveryNotRedeliverable;
use App\Webhooks\Application\InvalidWebhookEndpoint;
use App\Webhooks\Application\InvalidWebhookUrl;
use App\Webhooks\Application\UnknownWebhookDelivery;
use App\Webhooks\Application\UnknownWebhookEndpoint;
use App\Webhooks\Application\WebhookEndpointDisabled;
use App\Webhooks\Application\WebhookNotAllowed;
use App\Webhooks\Application\WebhookSecretsUnavailable;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * What the webhook use cases refuse, as the API answers it: a value that cannot
 * be used is 400 invalid_parameter with its name, a module the person may not
 * read 403, an endpoint or delivery the organisation does not have 404, an
 * attempt that cannot be made now 409, and an installation without a key to
 * seal secrets 503. The shared renderer writes all of them but the last, which
 * it would hide as an internal error; the operator's missing key is said plainly.
 */
final readonly class WebhookErrors
{
    public const string ROUTE_PREFIX = 'api_v1_webhook';
    public const string SERVICE_UNAVAILABLE = 'service_unavailable';

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onException(ExceptionEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if (!\is_string($route) || !str_starts_with($route, self::ROUTE_PREFIX)) {
            return;
        }
        $error = $event->getThrowable();
        if ($error instanceof WebhookSecretsUnavailable) {
            $event->setResponse(ProblemResponse::create(Response::HTTP_SERVICE_UNAVAILABLE, self::SERVICE_UNAVAILABLE, $error->getMessage()));
        } elseif ($error instanceof InvalidWebhookUrl) {
            $event->setThrowable(new InvalidParameter('url', $error->getMessage(), $error));
        } elseif ($error instanceof InvalidWebhookEndpoint) {
            $event->setThrowable(new InvalidParameter($error->parameter, $error->getMessage(), $error));
        } elseif ($error instanceof WebhookNotAllowed) {
            $event->setThrowable(new AccessDeniedHttpException($error->getMessage(), $error));
        } elseif ($error instanceof UnknownWebhookEndpoint || $error instanceof UnknownWebhookDelivery) {
            $event->setThrowable(new NotFoundHttpException($error->getMessage(), $error));
        } elseif ($error instanceof WebhookEndpointDisabled || $error instanceof DeliveryNotRedeliverable) {
            $event->setThrowable(new ConflictHttpException($error->getMessage(), $error));
        }
    }
}
