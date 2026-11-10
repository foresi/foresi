<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Application;

/**
 * Port: where time chosen in the dialog waits for the invoice form to issue
 * it, under a reference the form carries. The person who chose it is the one
 * who issues it.
 */
interface TimeSelections
{
    /**
     * @return string the reference
     */
    public function save(TimeSelection $selection): string;

    public function find(string $reference): ?TimeSelection;
}
