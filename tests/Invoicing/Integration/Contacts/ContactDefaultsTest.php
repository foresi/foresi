<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Contacts;

use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\InvoicingDefaults;
use Foresi\Invoicing\Contract\PartyInvoicingDefaults;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Domain\PartyInvoicingDefault;
use Foresi\Party\Application\PartyDetails;
use Foresi\Party\Application\PartyHistoryEntry;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\PartyRow;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Shared\Domain\Currency;
use Foresi\Tests\Invoicing\Application\InvoiceTestCase;

/**
 * The invoicing defaults Invoicing keeps beside each contact, as the address
 * book shows them.
 */
final class ContactDefaultsTest extends InvoiceTestCase
{
    public function testAContactIsMadeWithTheStandardDefaults(): void
    {
        $this->em->clear();

        $row = $this->em->find(PartyInvoicingDefault::class, $this->customer->id());

        self::assertInstanceOf(PartyInvoicingDefault::class, $row);
        self::assertTrue($row->defaults()->equals(InvoicingDefaults::standard()));
    }

    public function testTheDefaultsAreKeptAndShowInTheContactsHistory(): void
    {
        $defaults = new InvoicingDefaults(30, Currency::EUR, DocumentLanguage::English, PaymentMethod::Card, true);
        $this->defaults()->change($this->customer->id(), $defaults);
        $this->em->flush();
        $this->em->clear();

        self::assertTrue($this->defaults()->of($this->customer->id())->equals($defaults));
        $party = $this->em->find(Party::class, $this->customer->id()) ?? self::fail();
        $entries = self::getContainer()->get(PartyDetails::class)->history($party);
        $changed = array_values(array_filter($entries, static fn (PartyHistoryEntry $entry): bool => 'updated' === $entry->action && PartyInvoicingDefault::class === $entry->entityClass));
        self::assertCount(1, $changed);
        self::assertSame('party', $changed[0]->subject);
        self::assertContains('invoicingDefaults.dueDays', $changed[0]->fields);
        self::assertSame([], array_filter($entries, static fn (PartyHistoryEntry $entry): bool => 'created' === $entry->action && PartyInvoicingDefault::class === $entry->entityClass), 'the row made with the contact is part of its creation');
    }

    public function testTheContactListSortsByTheDefaults(): void
    {
        $later = new Party(PartyKind::Person, 'Example Later Payer', null, null, '');
        $this->em->persist($later);
        $this->em->flush();
        $this->defaults()->change($later->id(), new InvoicingDefaults(60, Currency::CZK, DocumentLanguage::Czech, PaymentMethod::BankTransfer, false));
        $this->em->flush();

        $query = self::getContainer()->get(PartyQuery::class);
        $names = static fn (string $sort): array => array_map(static fn (PartyRow $row): string => $row->displayName, $query->search('Example', null, false, $sort, 1)->rows);

        self::assertSame('Example Later Payer', $names('-default_due_days')[0]);
        self::assertNotSame('Example Later Payer', $names('default_due_days')[0]);
    }

    private function defaults(): PartyInvoicingDefaults
    {
        return self::getContainer()->get(PartyInvoicingDefaults::class);
    }
}
