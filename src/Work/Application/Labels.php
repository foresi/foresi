<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\Label;

/**
 * The labels of the active tenant.
 */
interface Labels
{
    public function label(string $id): ?Label;

    /**
     * The label of the name whatever its case.
     */
    public function named(string $name): ?Label;

    /**
     * @return list<Label> by name
     */
    public function all(): array;

    public function add(Label $label): void;

    public function remove(Label $label): void;
}
