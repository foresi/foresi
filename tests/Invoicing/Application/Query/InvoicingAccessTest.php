<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application\Query;

use App\Invoicing\Application\InvoicingAccess;
use App\Invoicing\Application\InvoicingNotAllowed;

final class InvoicingAccessTest extends InvoicingReadTestCase
{
    public function testAnOwnerPassesAndIsNamed(): void
    {
        self::assertSame($this->owner->user()->id(), $this->access()->denyUnlessStaff());
    }

    public function testAMemberPassesAndIsNamed(): void
    {
        $this->signIn($this->member);

        self::assertSame($this->member->user()->id(), $this->access()->denyUnlessStaff());
    }

    public function testAGuestIsRefused(): void
    {
        $this->signIn($this->guest);

        $this->expectException(InvoicingNotAllowed::class);
        $this->access()->denyUnlessStaff();
    }

    public function testNobodySignedInIsRefused(): void
    {
        $this->signOut();

        $this->expectException(InvoicingNotAllowed::class);
        $this->access()->denyUnlessStaff();
    }

    public function testAnOwnerOfAnotherOrganisationIsRefused(): void
    {
        $this->switchTo(\App\Tenancy\Domain\TenantId::generate());

        $this->expectException(InvoicingNotAllowed::class);
        $this->access()->denyUnlessStaff();
    }

    public function testTheRefusalSaysWhy(): void
    {
        self::assertSame('Only the owners and members of the organisation work with its invoices.', new InvoicingNotAllowed()->getMessage());
    }

    private function access(): InvoicingAccess
    {
        return self::getContainer()->get(InvoicingAccess::class);
    }
}
