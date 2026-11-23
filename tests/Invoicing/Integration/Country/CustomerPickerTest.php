<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Invoicing\Application\CustomerPicker;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyRole;

final class CustomerPickerTest extends InvoiceTestCase
{
    private CustomerPicker $picker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->picker = self::getContainer()->get(CustomerPicker::class);
    }

    public function testIssuingAddsTheCustomerRole(): void
    {
        $this->customer->assignRoles([PartyRole::Supplier]);
        $this->em->flush();

        $this->issue->issue($this->input());

        self::assertSame([PartyRole::Supplier, PartyRole::Customer], $this->customer->roles());
    }

    public function testAPlainContactBecomesACustomer(): void
    {
        $this->issue->issue($this->input());

        self::assertSame([PartyRole::Customer], $this->customer->roles());
    }

    public function testARefusedDocumentGivesNoRole(): void
    {
        $this->customer->assignRoles([PartyRole::Supplier]);
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.no_lines', fn (): \Foresi\Invoicing\Domain\Invoice => $this->issue->issue($this->input(lines: [])));

        self::assertSame([PartyRole::Supplier], $this->customer->roles());
    }

    public function testACustomerAlreadyIsLeftAlone(): void
    {
        $this->customer->assignRoles([PartyRole::Customer, PartyRole::Supplier]);
        $this->em->flush();

        $this->issue->issue($this->input());

        self::assertSame([PartyRole::Customer, PartyRole::Supplier], $this->customer->roles());
    }

    public function testAnExistingDocumentKeepsItsCustomerWhenTheRoleChanges(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->customer->assignRoles([PartyRole::Supplier]);
        $this->em->flush();

        $this->edited($invoice, $this->input());

        self::assertSame($this->customer->id(), $this->reloaded($invoice)->customerParty()->id());
    }

    protected function customer(?string $vatId = 'CZ87654320', ?Address $address = null): Party
    {
        $party = parent::customer($vatId, $address);
        $this->em->persist(new PartyAddress($party, AddressType::Delivery, new Address('Delivery Street 3', 'Customer City', '200 00', 'CZ')));

        return $party;
    }
}
