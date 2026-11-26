<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Doctrine;

use Foresi\Catalog\Application\CatalogWork;
use Foresi\Shared\Contract\UnitOfWork;

/**
 * The unit of work of the catalog on the shared entity manager, as the
 * webhooks run theirs: a unit inside another is part of it; on a failure the
 * entity manager is replaced; the outbox learns how the outermost unit ended.
 */
final readonly class DoctrineCatalogWork implements CatalogWork
{
    public function __construct(
        private UnitOfWork $work,
    ) {
    }

    public function run(callable $work): mixed
    {
        return $this->work->run($work);
    }
}
