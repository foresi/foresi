<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * Where a document stands against the planned occurrence it may belong to.
 * Auto and confirmed pair it; proposed waits for a person; declined says the
 * person refused every candidate.
 */
enum PairingState: string
{
    case Auto = 'auto';
    case Confirmed = 'confirmed';
    case Proposed = 'proposed';
    case Declined = 'declined';
}
