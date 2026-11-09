<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * What a time report reads: the days from and until, both included, and
 * optionally one project, one client, one person, billable or not and
 * invoiced or not (Freelo reports, Productive "invoiced = no").
 */
final readonly class TimeReportFilter
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $until,
        public ?string $projectId = null,
        public ?string $clientId = null,
        public ?string $membershipId = null,
        public ?bool $billable = null,
        public ?bool $invoiced = null,
    ) {
    }
}
