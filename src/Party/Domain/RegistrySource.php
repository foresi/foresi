<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

enum RegistrySource: string
{
    case Vies = 'vies';
    case UnreliablePayer = 'unreliable_payer';
}
