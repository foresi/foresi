<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\VatId;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The tax authority's register of unreliable VAT payers. Never throws: an
 * unreachable register gives a check with the result Unavailable.
 */
#[AutoconfigureTag(self::TAG)]
interface VatPayerReliability
{
    public const string TAG = 'app.vat_payer_reliability';

    public function check(VatId $vatId): ReliabilityResult;

    /**
     * Checks many VAT ids at once; the register is asked in batches.
     *
     * @param list<VatId> $vatIds
     *
     * @return array<string, ReliabilityResult> keyed by the VAT id string
     */
    public function checkMany(array $vatIds): array;
}
