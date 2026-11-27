<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application;

/**
 * A movement was not paired or unpaired, and nothing was stored. The reason is one
 * of the constants, a stable name the screen translates. A document that refused
 * the payment gives the problem itself: the translation key its module shows for
 * it, with its parameters.
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
    /**
     * The bank did not vouch for the movement, and the person pairing it did not
     * confirm they checked it in their internet banking.
     */
    public const string NOT_CHECKED_IN_BANK = 'not_checked_in_bank';
    /** An invoice chosen has nothing left to pay. */
    public const string INVOICE_SETTLED = 'invoice_settled';
    /** Unpairing a movement that has no payment. */
    public const string NOT_PAIRED = 'not_paired';
    /** No installed module pays documents from movements. */
    public const string NO_PAIRING = 'no_pairing';
    /** A document chosen does not exist. */
    public const string UNKNOWN_DOCUMENT = 'unknown_document';
    /** The document refused the payment; the problem says why. */
    public const string PAYMENT_REFUSED = 'payment_refused';
    /** The document is in another currency than the movement. */
    public const string CURRENCY_MISMATCH = 'currency_mismatch';
    /** The document is in the trash. */
    public const string DOCUMENT_TRASHED = 'document_trashed';

    /**
     * @param array<string, int|string> $parameters
     */
    public function __construct(public readonly string $reason, public readonly ?string $problem = null, public readonly array $parameters = [])
    {
        parent::__construct(\sprintf('The pairing was refused: %s.', $reason));
    }
}
