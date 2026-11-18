<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Domain;

/**
 * What a party is to the firm. A party may have no role (a plain contact)
 * or several.
 */
enum PartyRole: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Partner = 'partner';
    case Authority = 'authority';
}
