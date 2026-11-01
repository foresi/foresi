<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Domain\ImportProfile;

interface ProfileRepository
{
    public function add(ImportProfile $profile): void;

    public function find(string $id): ?ImportProfile;

    /** The most recently updated profile remembered for these headers. */
    public function findByHeaders(string $targetKey, string $headerSignature): ?ImportProfile;

    /**
     * @return list<ImportProfile> newest first
     */
    public function forTarget(string $targetKey): array;
}
