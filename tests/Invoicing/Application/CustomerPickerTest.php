<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\CustomerOption;
use App\Invoicing\Application\CustomerPicker;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyFirmSettings;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;

final class CustomerPickerTest extends InvoiceTestCase
{
    private CustomerPicker $picker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->picker = self::getContainer()->get(CustomerPicker::class);
    }

    public function testASupplierOfThisFirmIsOffered(): void
    {
        $this->rolesForTheFirm($this->customer, [PartyRole::Supplier]);

        self::assertSame(['Example Customer a.s.'], $this->names('Example Customer', $this->firm));
    }

    public function testACustomerOfThisFirmIsACustomerThereOnly(): void
    {
        $other = $this->firm(registrationNumber: '12345687');
        $this->customer->assignRoles([PartyRole::Supplier]);
        $this->rolesForTheFirm($this->customer, [PartyRole::Customer]);
        $contacts = self::getContainer()->get(ContactSettingsForFirm::class);

        self::assertTrue($contacts->isCustomer($this->customer, $this->firm));
        self::assertFalse($contacts->isCustomer($this->customer, $other));
        self::assertSame(['Example Customer a.s.'], $this->names('Example Customer', $other));
    }

    public function testAContactWithoutRolesIsOffered(): void
    {
        self::assertSame([], $this->customer->roles());

        self::assertSame(['Example Customer a.s.'], $this->names('Example Customer', $this->firm));
    }

    public function testAPartnerWithoutTheCustomerRoleIsOffered(): void
    {
        $this->customer->assignRoles([PartyRole::Partner]);
        $this->em->flush();

        self::assertSame(['Example Customer a.s.'], $this->names('Example Customer', $this->firm));
    }

    public function testCustomersComeFirst(): void
    {
        $this->customer->assignRoles([PartyRole::Customer]);
        $supplier = new Party(PartyKind::Person, 'Example A Supplier', null, null, '');
        $supplier->assignRoles([PartyRole::Supplier]);
        $this->em->persist($supplier);
        $this->em->flush();

        self::assertSame(['Example Customer a.s.', 'Example A Supplier'], $this->names('Example', $this->firm));
    }

    public function testAContactArchivedForThisFirmIsNotOffered(): void
    {
        $other = $this->firm(registrationNumber: '12345687');
        $settings = new PartyFirmSettings($this->customer, $this->firm);
        $settings->archive(new \DateTimeImmutable('2026-10-01'));
        $this->em->persist($settings);
        $this->em->flush();

        self::assertSame([], $this->picker->search('Example Customer', $this->firm));
        self::assertSame(['Example Customer a.s.'], $this->names('Example Customer', $other));
    }

    public function testAContactArchivedForAllIsNotOffered(): void
    {
        $this->customer->archive(new \DateTimeImmutable('2026-10-01'));
        $this->em->flush();

        self::assertSame([], $this->picker->search('Example Customer', $this->firm));
    }

    public function testPicksFirmEmails(): void
    {
        $this->em->persist(new PartyEmail($this->customer, 'firm@example.com', EmailPurpose::Invoices, $this->firm));
        $other = $this->firm(registrationNumber: '12345687');
        $this->em->flush();

        self::assertSame('firm@example.com', $this->only('Example Customer')->email);
        self::assertSame('billing@example.com', $this->picker->search('Example Customer', $other)[0]->email);
    }

    public function testTheGeneralEmailIsTheFallback(): void
    {
        $party = $this->customer();
        $party->rename('Example General a.s.');
        $this->em->flush();
        foreach ($this->em->getRepository(PartyEmail::class)->findBy(['party' => $party]) as $email) {
            $this->em->remove($email);
        }
        $this->em->flush();
        $this->em->persist(new PartyEmail($party, 'general@example.com', EmailPurpose::General));
        $this->em->flush();

        self::assertSame('general@example.com', $this->only('Example General')->email);
    }

    public function testPicksFirmDefaults(): void
    {
        $own = new InvoicingDefaults(30, Currency::CZK, DocumentLanguage::English, PaymentMethod::Cash, true);
        $settings = new PartyFirmSettings($this->customer, $this->firm);
        $settings->changeInvoicingDefaults($own);
        $this->em->persist($settings);
        $other = $this->firm(registrationNumber: '12345687');
        $this->em->flush();

        self::assertTrue($own->equals($this->only('Example Customer')->defaults));
        self::assertTrue(InvoicingDefaults::standard()->equals($this->picker->search('Example Customer', $other)[0]->defaults));
    }

    public function testAnOptionCarriesWhatTheFormShows(): void
    {
        $option = $this->only('Example Customer');

        self::assertSame($this->customer->id(), $option->id);
        self::assertSame('Example Customer a.s.', $option->name);
        self::assertSame('87654320', $option->registrationNumber);
        self::assertSame('CZ87654320', $option->vatId);
        self::assertSame('Customer Street 2', $option->billing->street);
    }

    public function testAContactWithoutABillingAddressIsOfferedAndTheSaveExplains(): void
    {
        $party = new Party(PartyKind::Person, 'Example Nomad', null, null, '');
        $this->em->persist($party);
        $this->em->flush();

        $option = $this->only('Example Nomad');

        self::assertSame('', $option->billing->street);
        self::assertSame('', $option->billing->countryCode);
    }

    public function testThreeCharactersOrAnIdNumberSearch(): void
    {
        self::assertSame([], $this->picker->search('Ex', $this->firm));
        self::assertSame([], $this->picker->search('  E  ', $this->firm));
        self::assertSame(['Example Customer a.s.'], $this->names('Exa', $this->firm));
        self::assertSame(['Example Customer a.s.'], $this->names('87', $this->firm));
        self::assertSame(['Example Customer a.s.'], $this->names('8765 4320', $this->firm));
    }

    public function testAtMostTenAreOffered(): void
    {
        for ($i = 0; $i < 11; ++$i) {
            $this->em->persist(new Party(PartyKind::Person, 'Example Many '.$i, null, null, ''));
        }
        $this->em->flush();

        self::assertCount(CustomerPicker::LIMIT, $this->picker->search('Example Many', $this->firm));
    }

    public function testCustomersBehindAFullPageOfSuppliersComeFirst(): void
    {
        for ($i = 0; $i < 26; ++$i) {
            $supplier = new Party(PartyKind::Person, 'Example A Supplier '.$i, null, null, '');
            $supplier->assignRoles([PartyRole::Supplier]);
            $this->em->persist($supplier);
        }
        $customer = new Party(PartyKind::Person, 'Example Z Customer', null, null, '');
        $customer->assignRoles([PartyRole::Customer]);
        $this->em->persist($customer);
        $this->em->flush();

        self::assertSame('Example Z Customer', $this->names('Example ', $this->firm, ['Example Customer a.s.'])[0]);
    }

    public function testOffersAreInNameOrder(): void
    {
        $this->em->persist(new Party(PartyKind::Person, 'Example B', null, null, ''));
        $this->em->persist(new Party(PartyKind::Person, 'Example A', null, null, ''));
        $this->em->flush();

        self::assertSame(['Example A', 'Example B', 'Example Customer a.s.'], $this->names('Example', $this->firm));
    }

    public function testTheOnlyFirmMakesAContactACustomerInTheSharedRoles(): void
    {
        $this->customer->assignRoles([PartyRole::Supplier]);
        $this->em->flush();

        $this->issue->issue($this->input());

        self::assertSame([PartyRole::Supplier, PartyRole::Customer], $this->customer->roles());
        self::assertFalse(self::getContainer()->get(ContactSettingsForFirm::class)->hasOwnRoles($this->customer, $this->firm));
    }

    public function testAPlainContactBecomesACustomer(): void
    {
        $this->issue->issue($this->input());

        self::assertSame([PartyRole::Customer], $this->customer->roles());
    }

    public function testAFirmAmongSeveralTakesTheRoleForItselfOnly(): void
    {
        $other = $this->firm(registrationNumber: '12345687');
        $this->customer->assignRoles([PartyRole::Supplier]);
        $this->em->flush();
        $contacts = self::getContainer()->get(ContactSettingsForFirm::class);

        $this->issue->issue($this->input());

        self::assertSame([PartyRole::Supplier], $this->customer->roles());
        self::assertSame([PartyRole::Supplier, PartyRole::Customer], $contacts->rolesFor($this->customer, $this->firm));
        self::assertSame([PartyRole::Supplier], $contacts->rolesFor($this->customer, $other));
    }

    public function testOwnRolesOfTheFirmGrowAndTheSharedOnesStay(): void
    {
        $this->customer->assignRoles([PartyRole::Partner]);
        $this->rolesForTheFirm($this->customer, [PartyRole::Supplier]);

        $this->issue->issue($this->input());

        self::assertSame([PartyRole::Partner], $this->customer->roles());
        self::assertSame([PartyRole::Supplier, PartyRole::Customer], self::getContainer()->get(ContactSettingsForFirm::class)->rolesFor($this->customer, $this->firm));
    }

    public function testARefusedDocumentGivesNoRole(): void
    {
        $this->customer->assignRoles([PartyRole::Supplier]);
        $this->em->flush();

        $this->assertRefusedFor('invoice.problem.no_lines', fn (): \App\Invoicing\Domain\Invoice => $this->issue->issue($this->input(lines: [])));

        self::assertSame([PartyRole::Supplier], $this->customer->roles());
    }

    public function testACustomerAlreadyIsLeftAlone(): void
    {
        $this->customer->assignRoles([PartyRole::Customer, PartyRole::Supplier]);
        $this->em->flush();

        $this->issue->issue($this->input());

        self::assertSame([PartyRole::Customer, PartyRole::Supplier], $this->customer->roles());
        self::assertFalse(self::getContainer()->get(ContactSettingsForFirm::class)->hasOverride($this->customer, $this->firm));
    }

    public function testAnExistingDocumentKeepsItsCustomerWhenTheRoleChanges(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->rolesForTheFirm($this->customer, [PartyRole::Supplier]);

        $this->edited($invoice, $this->input());

        self::assertSame($this->customer->id(), $this->reloaded($invoice)->customerParty()->id());
    }

    /**
     * @param list<PartyRole> $roles
     */
    private function rolesForTheFirm(Party $party, array $roles): void
    {
        $settings = new PartyFirmSettings($party, $this->firm);
        $settings->changeRoles($roles);
        $this->em->persist($settings);
        $this->em->flush();
    }

    private function only(string $text): CustomerOption
    {
        $options = $this->picker->search($text, $this->firm);
        self::assertCount(1, $options);

        return $options[0];
    }

    /**
     * @param list<string> $without
     *
     * @return list<string>
     */
    private function names(string $text, \App\Party\Domain\LegalEntity $firm, array $without = []): array
    {
        $names = array_map(static fn (CustomerOption $option): string => $option->name, $this->picker->search($text, $firm));

        return array_values(array_diff($names, $without));
    }

    protected function customer(?string $vatId = 'CZ87654320', ?Address $address = null): Party
    {
        $party = parent::customer($vatId, $address);
        $this->em->persist(new PartyAddress($party, AddressType::Delivery, new Address('Delivery Street 3', 'Customer City', '200 00', 'CZ')));

        return $party;
    }
}
