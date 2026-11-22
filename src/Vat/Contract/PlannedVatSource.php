<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The planned items entered by hand for the VAT, which the flows of the VAT
 * periods may count a second time. Cash flow gives them through
 * Integration/CashFlow; it only reads, and changes or ends nothing.
 */
#[AutoconfigureTag(self::TAG)]
interface PlannedVatSource
{
    public const string TAG = 'app.vat_planned_item_source';

    /**
     * The active planned items in the category vat.
     *
     * @return list<PossibleDuplicate>
     */
    public function activeVatItems(): array;
}
