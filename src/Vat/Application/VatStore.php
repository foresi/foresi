<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Vat\Domain\PaymentAssignment;
use Foresi\Vat\Domain\PeriodEntry;
use Foresi\Vat\Domain\VatSettings;

/**
 * The records of the VAT module: the one row of the payment settings, what
 * people entered for the periods, and the bank movements they assigned.
 */
interface VatStore
{
    public function settings(): ?VatSettings;

    public function add(VatSettings $settings): void;

    public function entry(string $periodKey): ?PeriodEntry;

    /**
     * @return list<PeriodEntry>
     */
    public function entries(): array;

    public function addEntry(PeriodEntry $entry): void;

    public function removeEntry(PeriodEntry $entry): void;

    public function assignment(string $movementId): ?PaymentAssignment;

    /**
     * @return list<PaymentAssignment>
     */
    public function assignments(): array;

    public function addAssignment(PaymentAssignment $assignment): void;

    public function removeAssignment(PaymentAssignment $assignment): void;
}
