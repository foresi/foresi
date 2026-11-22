<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * The VAT status of the firm from a day on, until the next stretch begins.
 * The period length is set exactly when the status is registered. The day is
 * null only for the stretch that held from the start, when its first day is
 * not known (a firm whose status was kept before the stretches existed).
 */
final readonly class VatStretch
{
    public function __construct(
        public string $id,
        public VatRegistration $status,
        public ?VatPeriodLength $periodLength,
        public ?\DateTimeImmutable $validFrom,
    ) {
    }

    /**
     * Whether the stretch has begun on the day: an undated one always has.
     */
    public function hasBegunOn(\DateTimeImmutable $day): bool
    {
        return !$this->validFrom instanceof \DateTimeImmutable || $this->validFrom->format('Y-m-d') <= $day->format('Y-m-d');
    }
}
