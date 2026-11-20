<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Party\Application\LegalEntities;
use Foresi\Party\Application\UnknownLegalEntity;
use Foresi\Party\Domain\LegalEntity;

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
     * The firm, loaded again. Call it inside the unit of work, as a failed one
     * detaches what was loaded before it.
     *
     * @throws UnknownLegalEntity
     */
    public function firm(LegalEntity|string $firm, bool $lock = false): LegalEntity
    {
        $id = $firm instanceof LegalEntity ? $firm->id() : $firm;

        return $lock ? $this->firms->lock($id) : $this->firms->get($id);
    }
}
