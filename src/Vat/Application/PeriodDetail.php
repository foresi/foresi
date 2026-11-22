<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Vat\Contract\DocumentVat;

/**
 * One VAT period with the documents and the scheduled occurrences behind its
 * amounts. Those of a source the caller may not read are left out and the
 * source is listed in $restrictedSources: its amounts still count in the
 * totals.
 */
final readonly class PeriodDetail
{
    /**
     * @param list<DocumentVat>    $documents
     * @param list<string>         $restrictedSources
     * @param list<ScheduleAmount> $schedules
     */
    public function __construct(
        public PeriodList $list,
        public PeriodView $period,
        public array $documents,
        public array $restrictedSources,
        public array $schedules = [],
    ) {
    }
}
