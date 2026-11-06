<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentTotals;

/**
 * What a document comes to, in minor units of the home currency: net, VAT, gross
 * before the rounding of the total, and what is payable after it.
 */
final readonly class ImportedTotals
{
    public function __construct(public int $net, public int $vat, public int $gross, public int $payable)
    {
    }

    public static function of(DocumentTotals $totals): self
    {
        return new self($totals->net->minor(), $totals->vat->minor(), $totals->gross->minor(), $totals->payable->minor());
    }

    public function equals(self $other): bool
    {
        return $this->net === $other->net && $this->vat === $other->vat && $this->gross === $other->gross && $this->payable === $other->payable;
    }
}
