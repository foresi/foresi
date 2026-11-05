<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * What a person said about the payments of an advance invoice, where the rules
 * alone would not decide: issue a tax document on each payment, or none, because
 * the supply is not known with enough certainty for VAT to arise on receipt. An
 * advance without a choice follows the rules.
 */
enum TaxOnReceiptChoice: string
{
    case Issue = 'issue';
    case Skip = 'skip';
}
