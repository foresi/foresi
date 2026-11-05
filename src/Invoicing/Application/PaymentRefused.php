<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * A payment was not recorded, and nothing of it was stored; or a document with
 * payments was not moved to the trash, or one of a cancelled payment not out of
 * it. The reason is one of the constants, a stable name the screen translates.
 */
final class PaymentRefused extends \DomainException
{
    public const string NO_ALLOCATION = 'no_allocation';
    public const string AMOUNT_MISMATCH = 'amount_mismatch';
    public const string NOT_POSITIVE = 'not_positive';
    public const string WRONG_FIRM = 'wrong_firm';
    public const string CURRENCY_MISMATCH = 'currency_mismatch';
    public const string TRASHED = 'trashed';
    /** A tax document on payment is paid by the payment it taxes. */
    public const string NOT_PAYABLE = 'not_payable';
    public const string FUTURE_DATE = 'future_date';
    public const string SETTLEMENT_NOT_ALLOWED = 'settlement_not_allowed';
    public const string NOTE_TOO_LONG = 'note_too_long';
    /** A bank payment without its movement, or another payment naming one. */
    public const string NO_MOVEMENT = 'no_movement';
    /** The document has payments, so it cannot go to the trash. */
    public const string HAS_PAYMENTS = 'has_payments';
    /** The payment a tax document taxes is cancelled, so the document stays in the trash. */
    public const string CANCELLED = 'cancelled';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(\sprintf('The payment was refused: %s.', $reason));
    }
}
