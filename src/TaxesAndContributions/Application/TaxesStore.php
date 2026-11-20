<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application;

use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Domain\OwnerProfile;
use Foresi\TaxesAndContributions\Domain\StatusPeriod;

/**
 * The records of the taxes and contributions: the one owner profile and its
 * status periods.
 */
interface TaxesStore
{
    public function profile(): ?OwnerProfile;

    /**
     * @return list<StatusPeriod> by kind, then oldest first
     */
    public function statuses(): array;

    public function status(string $id): ?StatusPeriod;

    public function statusStarting(StatusKind $kind, \DateTimeImmutable $day): ?StatusPeriod;

    public function add(OwnerProfile|StatusPeriod $record): void;

    public function remove(StatusPeriod $status): void;
}
