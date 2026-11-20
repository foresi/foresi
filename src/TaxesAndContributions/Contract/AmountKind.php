<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * Which side of the sole trader's records an actual amount goes to: money
 * received for the business, or money paid for it.
 */
enum AmountKind: string
{
    case Income = 'income';
    case Cost = 'cost';
}
