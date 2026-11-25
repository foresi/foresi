<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure;

use Foresi\Party\Application\PartyNotAllowed;
use Foresi\Party\Application\PartyRefused;
use Foresi\Party\Contract\ContactCreation;
use Foresi\Party\Contract\Event\ContactKind;
use Foresi\Party\Contract\NewContact;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Tests\Party\Application\Query\ContactTestCase;

/**
 * A contact created from another module goes through the address book's own rules.
 */
final class ContactCreationTest extends ContactTestCase
{
    public function testItCreatesTheContactWithItsBillingAddressAndEmail(): void
    {
        $id = $this->creation()->create(new NewContact('person', 'Petra Example', 'Example Street 1', 'Example City', '100 00', 'CZ', email: 'petra@example.com', phone: '+420 111 222 333'));

        $summary = self::getContainer()->get(PartyDirectory::class)->get($id);
        self::assertSame(ContactKind::Person, $summary->kind);
        self::assertSame('Petra Example', $summary->displayName);
        self::assertSame(['Example Street 1', '100 00 Example City', 'CZ'], $summary->addressLines);
        self::assertSame('petra@example.com', $summary->email);
        self::assertSame('+420 111 222 333', $summary->phone);
    }

    public function testItRefusesAMissingStreetWithTheAddressBooksOwnRefusal(): void
    {
        try {
            $this->creation()->create(new NewContact('person', 'Petra Example', '', 'Example City', '100 00', 'CZ'));
            self::fail('A contact needs a street.');
        } catch (PartyRefused $refused) {
            self::assertSame(PartyRefused::REQUIRED, $refused->reason());
            self::assertSame('street', $refused->field());
        }
    }

    public function testADuplicateIsRefusedWithTheIdsItMatched(): void
    {
        $existing = $this->contact('Alpha Example s.r.o.', number: '12345679');

        try {
            $this->creation()->create(new NewContact('company', 'Alpha Again s.r.o.', 'Example Street 1', 'Example City', '100 00', 'CZ', registrationNumber: '12345679'));
            self::fail('The registration number is taken.');
        } catch (PartyRefused $refused) {
            self::assertSame(PartyRefused::DUPLICATE, $refused->reason());
            self::assertSame(['existing_ids' => [$existing->id()]], $refused->details());
        }
    }

    public function testAGuestIsRefused(): void
    {
        $this->signIn($this->guest);

        $this->expectException(PartyNotAllowed::class);
        $this->creation()->create(new NewContact('person', 'Petra Example', 'Example Street 1', 'Example City', '100 00', 'CZ'));
    }

    private function creation(): ContactCreation
    {
        return self::getContainer()->get(ContactCreation::class);
    }
}
