<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

/**
 * Why a change to a firm's bank account was refused.
 */
enum BankAccountRefusal: string
{
    /** Neither an IBAN nor a domestic account number of the firm's country. */
    case InvalidIban = 'invalid_iban';
    /** The firm has this IBAN already, maybe archived. */
    case Duplicate = 'duplicate';
    /** The account has bank movements, which are in its currency. */
    case CurrencyLocked = 'currency_locked';
    /** An archived account cannot be the primary one. */
    case ArchivedPrimary = 'archived_primary';
    case UnknownCurrency = 'unknown_currency';
}
