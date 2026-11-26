<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Contract\StaffCheck;

/**
 * Who sees and sets the source of the firm's exchange rates: its owners and
 * members, never a guest, on screens and with a token alike, since the rate a
 * document takes is the firm's matter.
 */
final readonly class ExchangeRateAccess
{
    public function __construct(private StaffCheck $staff, private AuditActor $actor)
    {
    }

    /**
     * @throws ExchangeRateRefused forbidden
     */
    public function denyUnlessStaff(): void
    {
        if (null === $this->actor->userId() || !$this->staff->isStaff()) {
            throw ExchangeRateRefused::forbidden();
        }
    }
}
