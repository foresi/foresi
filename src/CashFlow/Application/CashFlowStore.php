<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Domain\Checkpoint;
use Foresi\CashFlow\Domain\ExpectedDate;
use Foresi\CashFlow\Domain\MinimumBalance;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Party\Domain\FirmBankAccount;

/**
 * The records of the cash flow.
 */
interface CashFlowStore
{
    public function plannedItem(string $id): ?PlannedItem;

    /**
     * @return list<PlannedItem> the items, oldest first
     */
    public function plannedItems(): array;

    public function add(PlannedItem|Checkpoint|ExpectedDate|MinimumBalance $record): void;

    public function remove(PlannedItem|ExpectedDate|MinimumBalance $record): void;

    public function checkpoint(FirmBankAccount $account, \DateTimeImmutable $on): ?Checkpoint;

    /**
     * The latest checkpoint of the account on or before $on.
     */
    public function latestCheckpoint(FirmBankAccount $account, \DateTimeImmutable $on): ?Checkpoint;

    public function expectedDate(string $subjectType, string $subjectId): ?ExpectedDate;

    /**
     * @return array<string, \DateTimeImmutable> by "type:id"
     */
    public function expectedDates(): array;

    public function minimumBalance(): ?MinimumBalance;
}
