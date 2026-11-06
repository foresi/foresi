<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application\Import;

/**
 * One step of an iDoklad import: it reads one kind of record from the snapshot,
 * finds or creates what each became in Foresi, links it and reports the
 * outcome. Stages run in their order, so a stage can find the links of the
 * stages before it: documents after contacts and series.
 */
interface ImportStage
{
    /** The stage in the report. */
    public function key(): string;

    /** Lower runs first. */
    public function order(): int;

    public function run(ImportContext $context): void;
}
