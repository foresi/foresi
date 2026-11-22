<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Application;

/**
 * One unit of work of the catalog: the change and its event are committed
 * together or not at all. A unit inside another is part of it.
 */
interface CatalogWork
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed;
}
