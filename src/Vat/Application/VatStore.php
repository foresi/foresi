<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Vat\Domain\VatSettings;

/**
 * The records of the VAT module: the one row of the payment settings.
 */
interface VatStore
{
    public function settings(): ?VatSettings;

    public function add(VatSettings $settings): void;
}
