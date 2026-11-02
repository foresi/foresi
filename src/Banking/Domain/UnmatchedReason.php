<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Domain;

/**
 * Why an incoming movement did not pay an invoice by itself.
 */
enum UnmatchedReason: string
{
    /** The movement carries no variable symbol. */
    case NoSymbol = 'no_symbol';
    /** No open invoice of the firm has its symbol. */
    case NoInvoice = 'no_invoice';
    /** Several invoices have its symbol and the amount picks none of them. */
    case Ambiguous = 'ambiguous';
    /** Invoices with its symbol exist, all in another currency. */
    case CurrencyMismatch = 'currency_mismatch';
    /** The firm pairs by hand. */
    case AutoPairingOff = 'auto_pairing_off';
    /** Nothing trusted vouches for the message it came in. */
    case Unverified = 'unverified';
    /** Someone unpaired it, so it is never paired automatically again. */
    case Blocked = 'blocked';
    /** The message it came in failed its checks: it is never paired. */
    case Rejected = 'rejected';
    /** The invoice refused the payment the matcher chose, a received date in the future, say. */
    case Refused = 'refused';
}
