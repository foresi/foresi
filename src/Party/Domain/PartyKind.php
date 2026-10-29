<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

enum PartyKind: string
{
    case Person = 'person';
    case SoleTrader = 'sole_trader';
    case Company = 'company';
}
