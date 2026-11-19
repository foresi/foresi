<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Work\Domain\Label;

/**
 * The labels of tasks. A label in the trash is found only by trashed(),
 * inTrash() and trashedBefore().
 */
interface Labels
{
    public function label(string $id): ?Label;

    public function trashed(string $id): ?Label;

    /**
     * The label of the name whatever its case, out of the trash.
     */
    public function named(string $name): ?Label;

    /**
     * @return list<Label> by name
     */
    public function all(): array;

    /**
     * @return list<Label> the last deleted first
     */
    public function inTrash(): array;

    /**
     * @return list<Label> those put in the trash before the moment
     */
    public function trashedBefore(\DateTimeImmutable $moment): array;

    public function add(Label $label): void;

    public function remove(Label $label): void;
}
