<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

enum RegistryResult: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Reliable = 'reliable';
    case Unreliable = 'unreliable';
    /** The unreliable payer register does not list the subject: it is not a Czech VAT payer. */
    case NotRegistered = 'not_registered';
    case Unavailable = 'unavailable';

    public function isPossibleFor(RegistrySource $source): bool
    {
        return match ($this) {
            self::Unavailable => true,
            self::Valid, self::Invalid => RegistrySource::Vies === $source,
            self::Reliable, self::Unreliable, self::NotRegistered => RegistrySource::UnreliablePayer === $source,
        };
    }
}
