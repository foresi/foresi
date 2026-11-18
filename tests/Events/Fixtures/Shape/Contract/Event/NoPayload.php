<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Events\Fixtures\Shape\Contract\Event;

use Foresi\Shared\Domain\Event\DomainEvent;

#[DomainEvent('invoicing.invoice.c')]
final readonly class NoPayload
{
    public function __construct(
        public string $id,
    ) {
    }
}
