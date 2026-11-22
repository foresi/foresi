<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Events;

use Foresi\Documents\Application\DocumentsAccess;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Shared\Application\Event\EventReadAccess;

/**
 * The events of Documents are for the owners and members, as the documents are.
 */
final readonly class DocumentsEventAccess implements EventReadAccess
{
    public function __construct(private DocumentsAccess $access, private StaffCheck $staff)
    {
    }

    public function module(): string
    {
        return 'documents';
    }

    public function mayRead(): bool
    {
        return $this->access->isStaff();
    }

    public function mayUserRead(string $userId): bool
    {
        return $this->staff->isStaffMember($userId);
    }
}
