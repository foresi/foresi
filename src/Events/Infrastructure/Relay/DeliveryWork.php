<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Events\Infrastructure\Relay;

use Foresi\Shared\Contract\UnitOfWork;

/**
 * The transaction of one delivery, with the semantics of the unit of work of
 * the modules: the listener's changes, the events it records and the record of
 * its consumption are stored together or not at all. A unit of work the listener
 * opens inside it is part of it. On a failure the entity manager is replaced, so
 * the next message of the worker starts clean. It is the contract's UnitOfWork.
 */
final readonly class DeliveryWork
{
    public function __construct(
        private UnitOfWork $work,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed
    {
        return $this->work->run($work);
    }
}
