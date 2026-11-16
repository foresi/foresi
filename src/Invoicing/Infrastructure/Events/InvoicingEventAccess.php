<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Events;

use App\Identity\Application\StaffCheck;
use App\Invoicing\Application\InvoicingAccess;
use App\Invoicing\Application\InvoicingNotAllowed;
use App\Invoicing\Application\Query\PaymentAccess;
use App\Invoicing\Application\Query\PaymentsNotAllowed;
use App\Shared\Application\Event\EventReadAccess;

/**
 * The events of invoicing tell about invoices and payments, so they are read by
 * those who may read both: the owners and members of the organisation.
 */
final readonly class InvoicingEventAccess implements EventReadAccess
{
    public function __construct(private InvoicingAccess $invoices, private PaymentAccess $payments, private StaffCheck $staff)
    {
    }

    public function module(): string
    {
        return 'invoicing';
    }

    public function mayRead(): bool
    {
        try {
            $this->invoices->denyUnlessStaff();
            $this->payments->denyUnlessStaff();
        } catch (InvoicingNotAllowed|PaymentsNotAllowed) {
            return false;
        }

        return true;
    }

    /**
     * The rule both access services apply to the signed-in person, for anyone.
     */
    public function mayUserRead(string $userId): bool
    {
        return $this->staff->isStaffMember($userId);
    }
}
