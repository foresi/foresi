<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Integration\Invoicing;

/**
 * Whose time an invoice may take: the invoicing firm, the client, some of its
 * projects and the days from and until, both included.
 */
final readonly class TimeScope
{
    /**
     * @param list<string> $projectIds
     */
    public function __construct(
        public string $clientId,
        public array $projectIds,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $until,
    ) {
    }
}
