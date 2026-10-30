<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Application;

use App\Audit\Domain\AuditEntry;
use App\Party\Application\ArchiveParty;
use App\Party\Application\CreateParty;
use App\Party\Application\LinkContactPerson;
use App\Party\Application\PartyDetails;
use App\Party\Application\PartyInput;
use App\Party\Application\PartyQuery;
use App\Party\Application\PartyRow;
use App\Party\Application\UpdateParty;
use App\Party\Domain\Address;
use App\Party\Domain\Party;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AddressBookWritesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TenantId $tenant;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->tenant = TenantId::generate();
        self::getContainer()->get(TenantContext::class)->switchTo($this->tenant);
    }

    public function testCreateFromInputStoresTheNoteTheBillingAddressAndTheEmail(): void
    {
        $party = $this->create('Example Company', email: 'info@example.com', note: 'Pays late');

        self::assertSame('Pays late', $party->privateNote());
        self::assertSame('info@example.com', $this->details()->generalEmail($party)?->address());
        self::assertSame('Example City', $this->details()->billingAddress($party)?->address()->city);
    }

    public function testUpdateChangesTheFieldsTheBillingAddressAndTheEmailInOneUnitOfWork(): void
    {
        $party = $this->create('Example Company', email: 'old@example.com');

        $this->update($party, new PartyInput(PartyKind::Company, 'Renamed Company', null, VatId::fromString('CZ12345695'), 'new@example.com', '+420000000000', [PartyRole::Supplier], new Address('Main 2', 'Other City', '200 00', 'CZ'), 'Note'));
        $this->em->clear();

        $party = $this->query()->find((int) $party->id()) ?? self::fail();
        self::assertSame('Renamed Company', $party->displayName());
        self::assertSame('CZ12345695', $party->vatId()?->toString());
        self::assertSame([PartyRole::Supplier], $party->roles());
        self::assertSame('+420000000000', $party->phone());
        self::assertSame('Note', $party->privateNote());
        self::assertSame('Other City', $this->details()->billingAddress($party)?->address()->city);
        self::assertSame('new@example.com', $this->details()->generalEmail($party)?->address());
        self::assertCount(1, $this->details()->emails($party));
    }

    public function testUpdateAddsAMissingEmailAndRemovesAClearedOne(): void
    {
        $party = $this->create('Example Company');
        self::assertNotInstanceOf(PartyEmail::class, $this->details()->generalEmail($party));

        $this->update($party, $this->input('Example Company', 'added@example.com'));
        self::assertSame('added@example.com', $this->details()->generalEmail($party)?->address());

        $this->update($party, $this->input('Example Company', ''));
        $this->em->clear();
        self::assertSame([], $this->details()->emails($this->query()->find((int) $party->id()) ?? self::fail()));
    }

    public function testUpdateThatBreaksADomainRuleChangesNothing(): void
    {
        $party = $this->create('Example Company', email: 'info@example.com', number: '12345687');
        $id = (int) $party->id();

        try {
            $this->update($party, new PartyInput(PartyKind::Company, 'Changed', null, null, 'other@example.com', '', [], new Address('Main 2', 'Other City', '200 00', 'CZ')));
            self::fail('A company without identifiers must be rejected.');
        } catch (\DomainException) {
        }

        // The session is usable again and the stored contact is untouched.
        $stored = $this->query()->find($id) ?? self::fail();
        self::assertSame('Example Company', $stored->displayName());
        self::assertSame('info@example.com', $this->details()->generalEmail($stored)?->address());
    }

    public function testArchiveAndRestoreAreRepeatableAndAudited(): void
    {
        $party = $this->create('Example Company');
        $archive = self::getContainer()->get(ArchiveParty::class);

        $archive->archive([$party]);
        $first = $party->archivedAt();
        $archive->archive([$party]);

        self::assertTrue($party->isArchived());
        self::assertEquals($first, $party->archivedAt());
        $archive->restore([$party]);
        self::assertFalse($party->isArchived());
        $updates = $this->em->getRepository(AuditEntry::class)->findBy(['entityClass' => Party::class, 'entityId' => (string) $party->id()]);
        self::assertGreaterThanOrEqual(3, \count($updates), 'created, archived, restored');
    }

    public function testLinkingAPrimaryContactDemotesThePreviousPrimaryOne(): void
    {
        $company = $this->create('Example Company');
        $first = $this->create('First Person', kind: PartyKind::Person);
        $second = $this->create('Second Person', kind: PartyKind::Person);
        $link = self::getContainer()->get(LinkContactPerson::class);

        $one = $link->link($company, $first, 'Accountant', true);
        $two = $link->link($company, $second, 'Director', true);

        self::assertFalse($one->isPrimary());
        self::assertTrue($two->isPrimary());
        self::assertCount(2, $this->details()->contactLinks($company));
        self::assertCount(1, $this->details()->contactLinks($first));
    }

    public function testLinkingTheSamePairTwiceOrAPersonToAPersonIsRejected(): void
    {
        $company = $this->create('Example Company');
        $person = $this->create('Test Person', kind: PartyKind::Person);
        $link = self::getContainer()->get(LinkContactPerson::class);
        $link->link($company, $person, '', false);

        try {
            $link->link($company, $person, '', false);
            self::fail('The pair is linked already.');
        } catch (\DomainException) {
        }
        try {
            $link->link($person, $company, '', false);
            self::fail('A person is not a company.');
        } catch (\DomainException) {
        }

        self::assertCount(1, $this->details()->contactLinks($company));
    }

    public function testCandidatesAreActivePersonsNotLinkedYet(): void
    {
        $company = $this->create('Example Company');
        $linked = $this->create('Linked Person', kind: PartyKind::Person);
        $free = $this->create('Free Person', kind: PartyKind::Person);
        $gone = $this->create('Archived Person', kind: PartyKind::Person);
        self::getContainer()->get(ArchiveParty::class)->archive([$gone]);
        self::getContainer()->get(LinkContactPerson::class)->link($company, $linked, '', false);

        $candidates = $this->query()->contactPersonCandidates($company);

        self::assertSame([$free->id()], array_map(static fn (Party $party): ?int => $party->id(), $candidates));
    }

    public function testSearchIsTenantScopedAndSorts(): void
    {
        $this->create('Beta', number: '12345687');
        $this->create('alpha', number: '12345695');
        $context = self::getContainer()->get(TenantContext::class);
        $context->switchTo(TenantId::generate());
        $this->create('Foreign', number: '12345687');
        $context->switchTo($this->tenant);

        $page = $this->query()->search('', null, false, 'name', 1);

        self::assertSame(['alpha', 'Beta'], $this->names($page->rows));
        self::assertSame(['Beta', 'alpha'], $this->names($this->query()->search('', null, false, '-name', 1)->rows));
        self::assertSame(['Beta', 'alpha'], $this->names($this->query()->search('', null, false, 'registration', 1)->rows));
        self::assertSame(['alpha', 'Beta'], $this->names($this->query()->search('', null, false, 'nonsense', 1)->rows), 'an unknown sort falls back to the name');
        self::assertSame(2, $page->total);
        self::assertSame(1, $page->pages);
        self::assertSame(2, $this->query()->counts()->all);
        self::assertNotInstanceOf(Party::class, $this->query()->find(999999));
    }

    /**
     * @param list<PartyRow> $rows
     *
     * @return list<string>
     */
    private function names(array $rows): array
    {
        return array_map(static fn (PartyRow $row): string => $row->displayName, $rows);
    }

    private function create(string $name, string $email = '', ?PartyKind $kind = null, ?string $number = null, string $note = ''): Party
    {
        $kind ??= null === $number ? PartyKind::SoleTrader : PartyKind::Company;

        return self::getContainer()->get(CreateParty::class)->createFrom(new PartyInput($kind, $name, null === $number ? null : new RegistrationNumber('CZ', $number), null, $email, '', [PartyRole::Customer], new Address('Example Street 1', 'Example City', '100 00', 'CZ'), $note));
    }

    private function input(string $name, string $email): PartyInput
    {
        return new PartyInput(PartyKind::SoleTrader, $name, null, null, $email, '', [], new Address('Example Street 1', 'Example City', '100 00', 'CZ'));
    }

    private function update(Party $party, PartyInput $input): void
    {
        self::getContainer()->get(UpdateParty::class)->update($party, $input);
    }

    private function details(): PartyDetails
    {
        return self::getContainer()->get(PartyDetails::class);
    }

    private function query(): PartyQuery
    {
        return self::getContainer()->get(PartyQuery::class);
    }
}
