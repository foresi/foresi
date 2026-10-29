<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

enum VatStatus: string
{
    case Registered = 'registered';
    case NotRegistered = 'not_registered';
    case IdentifiedPerson = 'identified_person';
}
