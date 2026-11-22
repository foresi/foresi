<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * The VAT status of the instance's firm over time, with the length of its VAT
 * period (ADR 0039: one firm, so no firm id). Each stretch holds from its day
 * until the next one begins.
 */
interface FirmVatStatuses
{
    /**
     * Oldest first, the undated stretch first; empty before the first-run setup.
     *
     * @return list<VatStretch>
     */
    public function stretches(): array;

    /**
     * The stretch that holds on the day, or null when none has begun yet.
     */
    public function on(\DateTimeImmutable $day): ?VatStretch;
}
