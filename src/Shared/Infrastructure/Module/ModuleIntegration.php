<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Module;

use App\Shared\Domain\Module\ModuleId;

/**
 * The code one module keeps for another it depends on optionally, such as
 * App\Invoicing\Integration\CashFlow\ for Invoicing and Cash flow. It is
 * loaded only when that other module is installed (ADR 0033).
 */
final readonly class ModuleIntegration
{
    public function __construct(
        public ModuleId $module,
        public ModuleId $uses,
        public string $namespace,
        public string $directory,
        public bool $loaded,
    ) {
    }
}
