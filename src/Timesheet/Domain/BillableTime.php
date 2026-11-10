<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Domain;

use App\Shared\Domain\Money;

/**
 * An entry of time to bill, with what names its line and the rate it is billed
 * at. The fingerprint is what the entry said when it was read, for the issue to
 * tell an edit since.
 */
final readonly class BillableTime
{
    public function __construct(
        public string $entryId,
        public string $projectId,
        public string $projectName,
        public ?string $taskId,
        public ?string $taskTitle,
        public string $membershipId,
        public string $personName,
        public \DateTimeImmutable $workedOn,
        public int $minutes,
        public string $note,
        public Money $rate,
        public string $fingerprint = '',
    ) {
    }
}
