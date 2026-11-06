<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Seals secrets kept in the database with keys held outside it, so a copy of
 * the database alone opens none of them.
 */
interface Sealer
{
    /**
     * @throws SealerUnavailable when no key is configured
     */
    public function seal(string $secret): string;

    /**
     * @throws SealerUnavailable when no configured key opens the sealed value
     */
    public function open(string $sealed): string;

    /**
     * Whether a key is configured; without one nothing can be sealed.
     */
    public function isConfigured(): bool;
}
