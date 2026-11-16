<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application\Event;

use App\Shared\Domain\Event\DomainEventPayload;

/**
 * Records a contract event in the transaction that makes its fact true, so the
 * event exists exactly when the change is committed. Recording outside an
 * active transaction is a programming error.
 */
interface EventRecorder
{
    /**
     * @throws \LogicException when no transaction is active
     */
    public function record(DomainEventPayload $event): void;
}
