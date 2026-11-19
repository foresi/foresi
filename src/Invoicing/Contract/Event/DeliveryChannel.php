<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract\Event;

/**
 * How a document was sent to the customer.
 */
enum DeliveryChannel: string
{
    case Email = 'email';
    case Manual = 'manual';
}
