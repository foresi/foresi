<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Domain;

enum AddressType: string
{
    case Billing = 'billing';
    case Delivery = 'delivery';
    case Postal = 'postal';
    case Other = 'other';
}
