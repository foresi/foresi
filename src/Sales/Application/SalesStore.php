<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Foresi\Sales\Contract\BillingDocumentType;
use Foresi\Sales\Domain\BillingPlanEntry;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealContact;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Domain\DealLink;
use Foresi\Sales\Domain\LostReason;
use Foresi\Sales\Domain\Stage;

/**
 * The deals, their lines, contact persons, links and billing plans, and the
 * stages and lost reasons. A write is flushed by the unit of work around it.
 */
interface SalesStore
{
    /** A deal, in the trash too; null for an unknown id. */
    public function deal(string $id): ?Deal;

    /**
     * The deals not in the trash the filter lets through, the newest first.
     *
     * @return list<Deal>
     */
    public function deals(DealFilter $filter): array;

    /** How many open deals, not in the trash, sit in the stage. */
    public function openDealsIn(string $stageId): int;

    /**
     * @return list<DealLine> in their order
     */
    public function linesOf(string $dealId): array;

    /** The line linked to the project, whatever its deal's state; null for none. */
    public function lineOfProject(string $projectId): ?DealLine;

    /**
     * @return list<DealContact> in their order
     */
    public function contactsOf(string $dealId): array;

    /**
     * The links the deal is either end of, the oldest first.
     *
     * @return list<DealLink>
     */
    public function linksOf(string $dealId): array;

    public function stage(string $id): ?Stage;

    public function stageByKey(string $key): ?Stage;

    /**
     * @return list<Stage> in their order, the archived ones only when asked for, after the others
     */
    public function stages(bool $includeArchived = false): array;

    public function lostReason(string $id): ?LostReason;

    public function lostReasonByKey(string $key): ?LostReason;

    /**
     * @return list<LostReason> in their order, the archived ones only when asked for, after the others
     */
    public function lostReasons(bool $includeArchived = false): array;

    /**
     * The billing plan of a deal, the oldest entry first.
     *
     * @return list<BillingPlanEntry>
     */
    public function billingPlanOf(string $dealId): array;

    /** A planned invoice of any deal; null for an unknown id. */
    public function billingEntry(string $id): ?BillingPlanEntry;

    /** The entry that follows the recurring line; null for none. */
    public function billingEntryOfLine(string $lineId): ?BillingPlanEntry;

    /**
     * The entries the document fulfils, of any deal.
     *
     * @return list<BillingPlanEntry>
     */
    public function billingEntriesFulfilledBy(BillingDocumentType $type, string $documentId): array;

    /**
     * The entries no document fulfils, of the won deals not in the trash: what
     * the forecast counts. The oldest first.
     *
     * @return list<BillingPlanEntry>
     */
    public function openBillingEntries(): array;

    public function add(Deal|DealLine|DealContact|DealLink|Stage|LostReason|BillingPlanEntry $record): void;

    public function remove(DealLine|DealContact|DealLink|BillingPlanEntry $record): void;
}
