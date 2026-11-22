<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Contract\FirmVatStatuses;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Contract\VatStretch;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\VatStatus;
use Foresi\Party\Domain\VatStatusPeriod;

/**
 * The VAT statuses of the firm as other modules read them. A firm stored
 * without any (written by code other than the first-run setup) holds its kept
 * status from the start, as the migration makes it: one undated stretch, under
 * the firm's id, a payer by the month.
 */
final readonly class VatStatusReads implements FirmVatStatuses
{
    public function __construct(private VatStatusPeriods $periods, private InstanceFirm $firm)
    {
    }

    public function stretches(): array
    {
        $stored = array_map(static fn (VatStatusPeriod $period): VatStretch => $period->stretch(), $this->periods->all());
        if ([] !== $stored) {
            return $stored;
        }
        $firm = $this->firm->find();
        if (!$firm instanceof LegalEntity) {
            return [];
        }
        $status = $firm->vatStatus();

        return [new VatStretch($firm->id(), $status->registration(), VatStatus::Registered === $status ? VatPeriodLength::Month : null, null)];
    }

    public function on(\DateTimeImmutable $day): ?VatStretch
    {
        return self::holding($this->stretches(), $day);
    }

    /**
     * The last stretch that has begun on the day.
     *
     * @param list<VatStretch> $stretches oldest first
     */
    public static function holding(array $stretches, \DateTimeImmutable $day): ?VatStretch
    {
        $holding = null;
        foreach ($stretches as $stretch) {
            if ($stretch->hasBegunOn($day)) {
                $holding = $stretch;
            }
        }

        return $holding;
    }
}
