<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Api;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * A parameter of the request that cannot be used, named as the client sent it.
 * The API answers it 400 invalid_parameter with the name of the parameter.
 */
final class InvalidParameter extends BadRequestHttpException
{
    public function __construct(public readonly string $parameter, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }
}
