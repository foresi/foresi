<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Events;

use Foresi\Identity\Application\StaffCheck;
use Foresi\Shared\Application\Event\EventReadAccess;
use Foresi\Vat\Application\VatAccess;
use Foresi\Vat\Application\VatRefused;

/**
 * The events of the VAT are the firm's, so its owners and members read them
 * and a guest never does.
 */
final readonly class VatEventAccess implements EventReadAccess
{
    public function __construct(private VatAccess $access, private StaffCheck $staff)
    {
    }

    public function module(): string
    {
        return 'vat';
    }

    public function mayRead(): bool
    {
        try {
            $this->access->denyUnlessStaff();
        } catch (VatRefused) {
            return false;
        }

        return true;
    }

    public function mayUserRead(string $userId): bool
    {
        return $this->staff->isStaffMember($userId);
    }
}
