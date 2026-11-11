<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Application\StaffCheck;
use App\Party\Application\LegalEntities;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\LegalEntity;
use App\Purchasing\Domain\ReceivedDocumentRefused;

/**
 * Who works with received documents: the owners and the members of the
 * organisation, never its guests. Every use case of the module asks here
 * first, so the screens, the API and MCP are refused alike.
 */
final readonly class PurchasingAccess
{
    public function __construct(private StaffCheck $staff, private AuditActor $actor, private LegalEntities $firms)
    {
    }

    /**
     * @return string the id of the signed-in person
     *
     * @throws PurchasingNotAllowed
     */
    public function denyUnlessStaff(): string
    {
        $userId = $this->actor->userId();
        if (null === $userId || !$this->staff->isStaff()) {
            throw new PurchasingNotAllowed();
        }

        return $userId;
    }

    /**
     * A firm of the active tenant that still takes documents. Call it inside
     * the unit of work, as a failed one detaches what was loaded before it.
     *
     * @throws UnknownLegalEntity      also for a firm of another tenant
     * @throws ReceivedDocumentRefused with FIRM_ARCHIVED for an archived firm
     */
    public function activeFirm(LegalEntity|string $firm, bool $lock = false): LegalEntity
    {
        $id = $firm instanceof LegalEntity ? $firm->id() : $firm;
        $firm = $lock ? $this->firms->lock($id) : $this->firms->get($id);
        if ($firm->isArchived()) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::FIRM_ARCHIVED);
        }

        return $firm;
    }
}
