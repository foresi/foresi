<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application\Import;

/**
 * Port: the names of the contacts an iDoklad contact can match by name, which
 * are the contacts of the active tenant that are not archived and not the
 * contact person of a company.
 */
interface ContactNames
{
    /**
     * @return array<string, string> display name by contact id
     */
    public function all(): array;
}
