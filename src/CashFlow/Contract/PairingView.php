<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * How a document stands with Cash flow, for the panel of its page: the state, the occurrence it is paired with
 * (auto and confirmed) and the occurrences it may belong to, from which a person confirms one (proposed).
 */
final readonly class PairingView
{
    /**
     * @param list<PairingOption> $candidates
     */
    public function __construct(
        public PairingState $state,
        public ?PairingOption $current,
        public array $candidates,
    ) {
    }
}
