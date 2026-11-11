<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Application\StaffCheck;

/**
 * Who reads the address book: the owners and the members of the organisation,
 * never its guests. Every query service of the module asks here first, so the
 * screens, the API and MCP are refused alike.
 */
final readonly class PartyAccess
{
    public function __construct(private StaffCheck $staff, private AuditActor $actor)
    {
    }

    /**
     * @return string the id of the signed-in person
     *
     * @throws PartyNotAllowed
     */
    public function denyUnlessStaff(): string
    {
        $userId = $this->actor->userId();
        if (null === $userId || !$this->staff->isStaff()) {
            throw new PartyNotAllowed();
        }

        return $userId;
    }
}
