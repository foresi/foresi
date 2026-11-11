<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Api;

use App\Identity\Application\InsufficientScope;
use App\Shared\Infrastructure\Api\ProblemResponse;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A token without the scope the work needs is answered 403 with the scope it
 * lacks (RFC 6750), wherever the check is made.
 */
final readonly class ApiTokenErrors
{
    public const string INSUFFICIENT_SCOPE = 'insufficient_scope';

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onException(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();
        if (!$error instanceof InsufficientScope) {
            return;
        }
        $event->setResponse(ProblemResponse::create(
            Response::HTTP_FORBIDDEN,
            self::INSUFFICIENT_SCOPE,
            $error->getMessage(),
            ['WWW-Authenticate' => \sprintf('Bearer error="insufficient_scope", scope="%s"', $error->scope->value)],
        ));
    }
}
