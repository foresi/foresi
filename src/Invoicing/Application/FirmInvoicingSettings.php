<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\FirmInvoicing;
use App\Party\Domain\LegalEntity;

/**
 * The invoicing settings of the firms of the active tenant. Call save() inside
 * TransactionalWork, which flushes.
 */
interface FirmInvoicingSettings
{
    /**
     * The stored settings of a firm, or the standard ones, not stored yet.
     */
    public function for(LegalEntity $firm): FirmInvoicing;

    public function save(FirmInvoicing $settings): void;
}
