<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Query;

/**
 * A contact a received document may name as its supplier.
 */
final readonly class SupplierOption
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $registrationNumber,
        public ?string $vatId,
    ) {
    }
}
