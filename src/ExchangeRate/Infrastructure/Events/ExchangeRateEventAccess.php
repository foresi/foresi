<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Infrastructure\Events;

use Foresi\ExchangeRate\Application\ExchangeRateAccess;
use Foresi\ExchangeRate\Application\ExchangeRateRefused;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Shared\Application\Event\EventReadAccess;

/**
 * The events of the exchange rates are the firm's, so its owners and members
 * read them and a guest never does.
 */
final readonly class ExchangeRateEventAccess implements EventReadAccess
{
    public function __construct(private ExchangeRateAccess $access, private StaffCheck $staff)
    {
    }

    public function module(): string
    {
        return 'exchange-rate';
    }

    public function mayRead(): bool
    {
        try {
            $this->access->denyUnlessStaff();
        } catch (ExchangeRateRefused) {
            return false;
        }

        return true;
    }

    public function mayUserRead(string $userId): bool
    {
        return $this->staff->isStaffMember($userId);
    }
}
