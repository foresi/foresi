<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application\Import;

/**
 * Port: runs an import in one database transaction, which it commits or rolls
 * back as asked. A unit of work inside it is part of it. After a rollback,
 * whether asked for or after a failure, everything loaded before is detached,
 * so nothing rolled back is taken for stored.
 */
interface ImportTransaction
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work, bool $commit): mixed;
}
