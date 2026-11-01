<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * A code a country asks for on a document whose supply is taxed by the customer, with the key of its label.
 */
final readonly class SupplyCode
{
    public function __construct(public string $code, public string $labelKey)
    {
    }
}
