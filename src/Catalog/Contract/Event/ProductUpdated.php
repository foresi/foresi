<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Contract\Event;

use Foresi\Catalog\Contract\BillingKind;
use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * A product changed. The payload is thin (ADR 0030): read the
 * product itself for its name and price. It names the keys of the custom fields
 * that changed (ADR 0045), never their values; empty when none did.
 */
#[DomainEvent('catalog.product.updated', version: 1)]
final readonly class ProductUpdated implements DomainEventPayload
{
    /**
     * @param list<string> $customFields the keys of the custom fields that changed
     */
    public function __construct(
        public string $productId,
        public BillingKind $billingKind,
        public array $customFields = [],
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('product', $this->productId);
    }
}
