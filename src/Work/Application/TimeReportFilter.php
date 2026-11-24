<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Shared\Application\View\ViewFilter;

/**
 * What a time report reads: the days from and until, both included, and
 * optionally one project, one client, one person, billable or not and
 * invoiced or not (Freelo reports, Productive "invoiced = no"). The report
 * page reads the conditions of its saved view instead (TimeReportLists),
 * with no days: a date condition among them names the period, and without
 * one the report holds all the time. A text narrows the entries to those whose note, task,
 * project or client holds it, whatever its case.
 */
final readonly class TimeReportFilter
{
    public function __construct(
        public ?\DateTimeImmutable $from,
        public ?\DateTimeImmutable $until,
        public ?string $projectId = null,
        public ?string $clientId = null,
        public ?string $membershipId = null,
        public ?bool $billable = null,
        public ?bool $invoiced = null,
        public ViewFilter $conditions = new ViewFilter(),
        public string $text = '',
    ) {
    }

    /**
     * The report a view's working filter holds.
     */
    public static function ofView(ViewFilter $conditions, string $text = ''): self
    {
        return new self(null, null, conditions: $conditions, text: $text);
    }
}
