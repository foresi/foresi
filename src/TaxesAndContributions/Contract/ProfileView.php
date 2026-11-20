<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * The owner profile as a regime reads it: the dated statuses, the inputs of
 * each year and where each authority is paid.
 */
final readonly class ProfileView
{
    /**
     * @param list<StatusPeriod>         $statuses    by kind, then oldest first
     * @param array<int, YearInputsView> $years       by year
     * @param list<AuthorityAccountView> $authorities
     */
    public function __construct(
        public array $statuses,
        public array $years,
        public array $authorities,
    ) {
    }

    /**
     * The status of this kind on the day: the latest period that starts on
     * it or before, as the statuses are oldest first within their kind, so a status holds from its day until the next of its kind.
     */
    public function valueOn(StatusKind $kind, \DateTimeImmutable $day): ?StatusPeriod
    {
        $on = $day->format('Y-m-d');
        $found = null;
        foreach ($this->statuses as $status) {
            if ($kind === $status->kind && $status->validFrom->format('Y-m-d') <= $on) {
                $found = $status;
            }
        }

        return $found;
    }
}
