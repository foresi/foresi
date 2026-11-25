<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Contract;

/**
 * How far a bank movement is believed to come from the bank, whichever channel told
 * it. Only a verified movement may pay an invoice by itself; an unverified one is
 * recorded and suggested, and a failed source is rejected before its movements are
 * recorded.
 */
enum MovementTrust: string
{
    /** The channel vouches for the bank: an aligned DMARC pass, the bank's own API, a statement staff uploaded. */
    case Verified = 'verified';
    /** Nothing vouches for the source, nor speaks against it. */
    case Unverified = 'unverified';
    /** An aligned signature failed, or the sender is not the bank's. */
    case Failed = 'failed';

    /**
     * Whether this trust is higher than the other one: verified over unverified over failed.
     */
    public function isAbove(self $other): bool
    {
        return match ($this) {
            self::Verified => self::Verified !== $other,
            self::Unverified => self::Failed === $other,
            self::Failed => false,
        };
    }
}
