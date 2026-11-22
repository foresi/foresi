<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Contract\Event;

use Foresi\Catalog\Contract\BillingKind;
use Foresi\Shared\Domain\Event\DomainEvent;
use Foresi\Shared\Domain\Event\DomainEventPayload;
use Foresi\Shared\Domain\Event\EventSubject;

/**
 * A product was restored from the archive. The payload is thin (ADR 0030): read the
 * product itself for its name and price.
 */
#[DomainEvent('catalog.product.restored', version: 1)]
final readonly class ProductRestored implements DomainEventPayload
{
    public function __construct(
        public string $productId,
        public BillingKind $billingKind,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('product', $this->productId);
    }
}
