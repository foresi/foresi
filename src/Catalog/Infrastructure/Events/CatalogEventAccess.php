<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Events;

use Foresi\Catalog\Application\CatalogAccess;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Shared\Application\Event\EventReadAccess;

/**
 * Who reads the events of the catalog: the owners and members, as they work with it.
 */
final readonly class CatalogEventAccess implements EventReadAccess
{
    public function __construct(private CatalogAccess $access, private StaffCheck $staff)
    {
    }

    public function module(): string
    {
        return 'catalog';
    }

    public function mayRead(): bool
    {
        return $this->access->mayWork();
    }

    public function mayUserRead(string $userId): bool
    {
        return $this->staff->isStaffMember($userId);
    }
}
