<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * A movement was not paired or unpaired, and nothing was stored. The reason is one
 * of the constants, a stable name the screen translates.
 */
final class PairingRefused extends \DomainException
{
    /** Outgoing money pays no invoice of the firm. */
    public const string NOT_INCOMING = 'not_incoming';
    public const string ALREADY_PAIRED = 'already_paired';
    /** The message it came in failed its checks. */
    public const string REJECTED = 'rejected';
    public const string POSSIBLE_DUPLICATE = 'possible_duplicate';
    public const string OWN_TRANSFER = 'own_transfer';
    /** Unpairing a movement that has no payment. */
    public const string NOT_PAIRED = 'not_paired';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(\sprintf('The pairing was refused: %s.', $reason));
    }
}
