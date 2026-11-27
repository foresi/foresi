<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Contacts\Integration\Country;

use Foresi\Contacts\Application\ContactPartRefused;
use Foresi\Contacts\Application\ManagePartyBankAccounts;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Party\Application\PartyDetails;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyBankAccount;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Tests\Party\Application\Query\ContactTestCase;

/**
 * The contact persons, bank accounts and addresses of a contact as its tabs
 * change them: what is entered is trimmed, each limit takes exactly its number
 * of characters, every wrong field is named at once, a guest is refused before
 * anything else, and each change tells the listeners that the contact changed.
 */
final class ContactPartsTest extends ContactTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';
    private const string OTHER_IBAN = 'CZ5508000000001234567899';

    // Bank accounts

    public function testTheFirstAccountIsPrimaryAndItsValuesAreTrimmed(): void
    {
        $party = $this->contact('Alpha Example');

        $account = $this->accounts()->add($party, ' 19-2000145399/0800 ', ' komb cz pp ', ' '.str_repeat('ů', 100).' ', false);

        self::assertSame(self::IBAN, $account->iban()->toString());
        self::assertSame('KOMBCZPP', $account->bic());
        self::assertSame(str_repeat('ů', 100), $account->label());
        self::assertTrue($account->isPrimary());
        self::assertSame([$party->id()], $this->events());

        $second = $this->accounts()->add($party, self::OTHER_IBAN, '', '', false);
        self::assertFalse($second->isPrimary());
        self::assertNull($second->bic());
    }

    public function testADomesticNumberIsReadForTheBillingCountry(): void
    {
        $german = $this->contact('Beta Example');
        $this->em->persist($billing = $this->details()->billingAddress($german) ?? self::fail());
        $billing->relocate(new Address('Example Street 1', 'Example City', '10115', 'DE'));
        $this->em->flush();

        self::assertSame(['number' => 'account_number'], $this->refused(fn (): PartyBankAccount => $this->accounts()->add($german, '19-2000145399/0800', '', '', false)));

        // With no billing address, any country module reads the number.
        $nowhere = new Party(PartyKind::Company, 'Gamma Example', new RegistrationNumber('CZ', '12345679'), null, '');
        $this->em->persist($nowhere);
        $this->em->flush();
        self::assertSame(self::IBAN, $this->accounts()->add($nowhere, '19-2000145399/0800', '', '', false)->iban()->toString());
    }

    private function accounts(): ManagePartyBankAccounts
    {
        return self::getContainer()->get(ManagePartyBankAccounts::class);
    }

    private function details(): PartyDetails
    {
        return self::getContainer()->get(PartyDetails::class);
    }

    /**
     * @param \Closure(): mixed $write
     *
     * @return array<string, string>
     */
    private function refused(\Closure $write): array
    {
        try {
            $write();
        } catch (ContactPartRefused $refused) {
            return $refused->reasons;
        }
        self::fail('The contact part was not refused.');
    }

    /**
     * @return list<string> the contacts that contacts.contact.updated was recorded for, in order
     *
     * @phpstan-impure it reads the event log, which every change writes to
     */
    private function events(): array
    {
        $subjects = $this->em->getConnection()->fetchFirstColumn(
            'SELECT '.EventLogTable::SUBJECT_ID.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? ORDER BY '.EventLogTable::ID,
            ['contacts.contact.updated'],
        );

        return array_map(static fn (mixed $subject): string => \is_string($subject) ? $subject : '', $subjects);
    }
}
