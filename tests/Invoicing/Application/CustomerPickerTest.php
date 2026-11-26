<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\CustomerOption;
use Foresi\Invoicing\Application\CustomerPicker;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\InvoicingDefaults;
use Foresi\Invoicing\Contract\PartyInvoicingDefaults;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Shared\Domain\Currency;

final class CustomerPickerTest extends InvoiceTestCase
{
    private CustomerPicker $picker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->picker = self::getContainer()->get(CustomerPicker::class);
    }

    public function testASupplierIsOffered(): void
    {
        $this->customer->assignRoles([PartyRole::Supplier]);
        $this->em->flush();

        self::assertSame(['Example Customer a.s.'], $this->names('Example Customer'));
    }

    public function testAContactWithoutRolesIsOffered(): void
    {
        self::assertSame([], $this->customer->roles());

        self::assertSame(['Example Customer a.s.'], $this->names('Example Customer'));
    }

    public function testAPartnerWithoutTheCustomerRoleIsOffered(): void
    {
        $this->customer->assignRoles([PartyRole::Partner]);
        $this->em->flush();

        self::assertSame(['Example Customer a.s.'], $this->names('Example Customer'));
    }

    public function testCustomersComeFirst(): void
    {
        $this->customer->assignRoles([PartyRole::Customer]);
        $supplier = new Party(PartyKind::Person, 'Example A Supplier', null, null, '');
        $supplier->assignRoles([PartyRole::Supplier]);
        $this->em->persist($supplier);
        $this->em->flush();

        self::assertSame(['Example Customer a.s.', 'Example A Supplier'], $this->names('Example'));
    }

    public function testAnArchivedContactIsNotOffered(): void
    {
        $this->customer->archive(new \DateTimeImmutable('2026-10-01'));
        $this->em->flush();

        self::assertSame([], $this->picker->search('Example Customer'));
    }

    public function testPicksTheInvoicingEmail(): void
    {
        $this->em->persist(new PartyEmail($this->customer, 'general@example.com', EmailPurpose::General));
        $this->em->flush();

        self::assertSame('billing@example.com', $this->only('Example Customer')->email);
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

    public function testPicksTheInvoicingDefaultsOfTheContact(): void
    {
        $defaults = new InvoicingDefaults(30, Currency::CZK, DocumentLanguage::English, PaymentMethod::Cash, true);
        self::getContainer()->get(PartyInvoicingDefaults::class)->change($this->customer->id(), $defaults);
        $this->em->flush();

        self::assertTrue($defaults->equals($this->only('Example Customer')->defaults));
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
        self::assertSame([], $this->picker->search('Ex'));
        self::assertSame([], $this->picker->search('  E  '));
        self::assertSame(['Example Customer a.s.'], $this->names('Exa'));
        self::assertSame(['Example Customer a.s.'], $this->names('87'));
        self::assertSame(['Example Customer a.s.'], $this->names('8765 4320'));
    }

    public function testAtMostTenAreOffered(): void
    {
        for ($i = 0; $i < 11; ++$i) {
            $this->em->persist(new Party(PartyKind::Person, 'Example Many '.$i, null, null, ''));
        }
        $this->em->flush();

        self::assertCount(CustomerPicker::LIMIT, $this->picker->search('Example Many'));
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

        self::assertSame('Example Z Customer', $this->names('Example ', ['Example Customer a.s.'])[0]);
    }

    public function testOffersAreInNameOrder(): void
    {
        $this->em->persist(new Party(PartyKind::Person, 'Example B', null, null, ''));
        $this->em->persist(new Party(PartyKind::Person, 'Example A', null, null, ''));
        $this->em->flush();

        self::assertSame(['Example A', 'Example B', 'Example Customer a.s.'], $this->names('Example'));
    }

    private function only(string $text): CustomerOption
    {
        $options = $this->picker->search($text);
        self::assertCount(1, $options);

        return $options[0];
    }

    /**
     * @param list<string> $without
     *
     * @return list<string>
     */
    private function names(string $text, array $without = []): array
    {
        $names = array_map(static fn (CustomerOption $option): string => $option->name, $this->picker->search($text));

        return array_values(array_diff($names, $without));
    }

    protected function customer(?string $vatId = 'CZ87654320', ?Address $address = null): Party
    {
        $party = parent::customer($vatId, $address);
        $this->em->persist(new PartyAddress($party, AddressType::Delivery, new Address('Delivery Street 3', 'Customer City', '200 00', 'CZ')));

        return $party;
    }
}
