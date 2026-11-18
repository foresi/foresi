<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract\Event;

/**
 * The kind of a contact, as its events say it.
 */
enum ContactKind: string
{
    case Person = 'person';
    case SoleTrader = 'sole_trader';
    case Company = 'company';
}
