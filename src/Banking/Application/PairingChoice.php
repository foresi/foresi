<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * An invoice offered for a pairing, and why it is suggested, when it is.
 */
final readonly class PairingChoice
{
    /**
     * @param string|null $reason one of the BY_ constants of PairingChoices, or null for one not suggested
     */
    public function __construct(public Candidate $candidate, public ?string $reason)
    {
    }
}
