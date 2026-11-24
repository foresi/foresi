<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

/**
 * The default recipient of a supplier's costs over time: its versions,
 * latest month first.
 */
final readonly class SupplierDefaultsView
{
    /**
     * @param list<SupplierDefaultVersionView> $versions
     */
    public function __construct(
        public string $supplierId,
        public ?string $supplierName,
        public array $versions,
    ) {
    }
}
