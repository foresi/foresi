<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Domain\FirmInvoicing;

/**
 * The invoicing settings of the firm. Call save() inside
 * TransactionalWork, which flushes.
 */
interface FirmInvoicingSettings
{
    /**
     * The stored settings, or the standard ones, not stored yet.
     */
    public function get(): FirmInvoicing;

    public function save(FirmInvoicing $settings): void;
}
