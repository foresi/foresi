<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Contract\PairingState;
use Foresi\CashFlow\Domain\Checkpoint;
use Foresi\CashFlow\Domain\DocumentPairing;
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

    public function add(PlannedItem|Checkpoint|ExpectedDate|MinimumBalance|DocumentPairing $record): void;

    public function remove(PlannedItem|ExpectedDate|MinimumBalance|DocumentPairing $record): void;

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

    public function pairing(string $subjectType, string $subjectId): ?DocumentPairing;

    /**
     * The pairings, oldest first, of one state and/or one planned item.
     *
     * @return list<DocumentPairing>
     */
    public function pairings(?PairingState $state = null, ?string $plannedItemId = null): array;

    /**
     * The pairings that point at one occurrence (auto, confirmed or a proposal with that single candidate).
     *
     * @return list<DocumentPairing>
     */
    public function pairingsOfOccurrence(string $plannedItemId, \DateTimeImmutable $on): array;

    /**
     * The pairings of the documents of one counterparty, in any state.
     *
     * @return list<DocumentPairing>
     */
    public function pairingsOfCounterparty(string $counterpartyId): array;

    /**
     * The auto and confirmed pairings of the occurrences from one day to another, inclusive, in one query.
     *
     * @return list<DocumentPairing>
     */
    public function pairedOccurrences(\DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * Writes what is changed so far to the database, inside the unit of work around it. Only for a use case
     * that has to query what it has just changed.
     */
    public function flush(): void;
}
