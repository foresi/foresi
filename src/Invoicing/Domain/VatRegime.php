<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * Whether the issuer charges VAT on a document.
 */
enum VatRegime: string
{
    case Payer = 'payer';
    case NonPayer = 'non_payer';
}
