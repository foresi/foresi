<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

/**
 * One entry of the time report as its row shows it, every cell already in words; the
 * billable badge is null for time not billed.
 */
final readonly class TimeReportEntryRow
{
    /**
     * @param array{text: string, tone: string}|null $billable
     */
    public function __construct(
        public string $title,
        public string $project,
        public string $person,
        public string $date,
        public ?string $note,
        public ?array $billable,
        public string $time,
    ) {
    }
}
