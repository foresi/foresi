<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Contract\Event;

/**
 * How a document reached the customer.
 */
enum DeliveryChannel: string
{
    case Email = 'email';
    case Manual = 'manual';
}
