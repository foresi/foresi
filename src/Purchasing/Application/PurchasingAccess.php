<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\NoFirmYet;
use Foresi\Party\Domain\LegalEntity;

/**
 * Who works with received documents: the owners and the members of the
 * organisation, never its guests. Every use case of the module asks here
 * first, so the screens, the API and MCP are refused alike.
 */
final readonly class PurchasingAccess
{
    public function __construct(private StaffCheck $staff, private AuditActor $actor, private InstanceFirm $firm)
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
     * The one firm of the instance (ADR 0039), its row locked until the unit of
     * work ends when $lock is set: the first write of a unit that numbers a
     * document, as EvidenceNumbers asks. Call it inside the unit of work, as a
     * failed one detaches what was loaded before it.
     *
     * @throws NoFirmYet before the first-run setup
     */
    public function firm(bool $lock = false): LegalEntity
    {
        return $lock ? $this->firm->lock() : $this->firm->get();
    }
}
