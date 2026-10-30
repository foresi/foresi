<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Infrastructure;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Stands in for the handler: runs a callback and ends the stack.
 */
final readonly class CallbackMiddleware implements MiddlewareInterface
{
    public function __construct(private \Closure $callback)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        ($this->callback)();

        return $envelope;
    }
}
