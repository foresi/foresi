<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Party\Domain\LegalEntity;
use App\Purchasing\Domain\EvidenceSeries;

/**
 * The evidence series of the firms of the active tenant, one per firm.
 */
interface EvidenceSeriesCatalog
{
    public function ofFirm(LegalEntity $firm): ?EvidenceSeries;

    public function add(EvidenceSeries $series): void;
}
