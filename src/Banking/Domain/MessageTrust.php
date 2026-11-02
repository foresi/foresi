<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Domain;

/**
 * How far an inbound bank message is believed to come from its bank. Only a
 * verified message may pay an invoice by itself; an unverified one is recorded
 * and suggested, and a failed one is rejected.
 */
enum MessageTrust: string
{
    /** A trusted receiving server saw a passing, aligned DMARC, DKIM or ARC result. */
    case Verified = 'verified';
    /** Nothing trusted vouches for the sender, nor speaks against it. */
    case Unverified = 'unverified';
    /** An aligned signature failed, or the sender is not the bank's. */
    case Failed = 'failed';
}
