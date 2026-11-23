<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealContact;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Domain\DealLink;
use Foresi\Sales\Domain\LostReason;
use Foresi\Sales\Domain\Stage;

/**
 * The deals, their lines, contact persons and links, and the stages and lost
 * reasons. A write is flushed by the unit of work around it.
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

    public function add(Deal|DealLine|DealContact|DealLink|Stage|LostReason $record): void;

    public function remove(DealLine|DealContact|DealLink $record): void;
}
