<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain\Event;

/**
 * The facts of a contract event. Implemented by final readonly classes in a
 * module's Contract\Event namespace, which carry ids, codes, decimal strings
 * and ISO dates, never personal data. It names the one thing the event is about.
 */
interface DomainEventPayload
{
    public function subject(): EventSubject;
}
