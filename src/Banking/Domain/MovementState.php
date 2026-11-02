<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Domain;

/**
 * Where a bank movement stands. Only an unmatched movement waits for a pairing;
 * the others are done with, or never pair.
 */
enum MovementState: string
{
    /** Outgoing: kept for the record, never paired here. */
    case Recorded = 'recorded';
    /** Incoming and not paired; the reason says why. */
    case Unmatched = 'unmatched';
    /** A payment of an invoice came from it. */
    case Paired = 'paired';
    /** Money from another account of the same firm: no customer paid anything. */
    case OwnTransfer = 'own_transfer';
    /** The same movement came already in another message. */
    case PossibleDuplicate = 'possible_duplicate';
}
