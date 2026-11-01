<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Party\Domain\VatStatus;

/**
 * Whether the issuer charges VAT on a document.
 */
enum VatRegime: string
{
    case Payer = 'payer';
    case NonPayer = 'non_payer';

    /**
     * The regime of a new document of a firm; the document keeps it for good.
     */
    public static function of(VatStatus $status): self
    {
        return $status->chargesVat() ? self::Payer : self::NonPayer;
    }
}
