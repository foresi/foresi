<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Operation;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;
use Foresi\Shared\Infrastructure\Api\ApiErrors;
use Foresi\Shared\Infrastructure\Api\ProblemResponse;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * What a use case refuses through the shared contract, as the REST API answers
 * it (ADR 0032): an invalid value is 400 invalid_parameter with the parameter
 * and the reason, everything else the status of its kind with the reason as
 * the code and the details as members. It runs after the listeners of the
 * modules, which keep their own answers on their own routes, and before the
 * shared renderer.
 */
final readonly class OperationErrors
{
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: -32)]
    public function onException(ExceptionEvent $event): void
    {
        $refused = $event->getThrowable();
        if (!$refused instanceof Refused || !str_starts_with($event->getRequest()->getPathInfo(), ApiErrors::PATH_PREFIX)) {
            return;
        }
        $kind = $refused->kind();
        $field = $refused->field();
        $event->setResponse(RefusalKind::Invalid === $kind
            ? ProblemResponse::create($kind->status(), ApiErrors::INVALID_PARAMETER, $refused->getMessage(), extensions: ['parameter' => null === $field ? null : OperationField::wireName($field), 'reason' => $refused->reason()] + $refused->details())
            : ProblemResponse::create($kind->status(), $refused->reason(), $refused->getMessage(), extensions: $refused->details()));
    }
}
