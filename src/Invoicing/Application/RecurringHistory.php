<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * What a template imported from another system has issued there: how many
 * invoices and the date of the last one. Its first issue date may have passed;
 * the next date goes on after the last one.
 */
final readonly class RecurringHistory
{
    /**
     * @param string $importedFrom the identifier of the template in the system it was imported from
     */
    public function __construct(
        public string $importedFrom,
        public int $issuedCount,
        public ?\DateTimeImmutable $lastOccurrence,
    ) {
    }
}
