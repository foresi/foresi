<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * The kind of a party, as the summaries of the contract say it.
 */
enum ContactKind: string
{
    case Person = 'person';
    case SoleTrader = 'sole_trader';
    case Company = 'company';
}
