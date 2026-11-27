<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Contacts\Infrastructure;

use Foresi\Identity\Domain\Role;
use Foresi\Party\Contract\Field\ContactExtension;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The custom fields of a contact (ADR 0045) in the address book: a column, a filter, a
 * sort by a sortable field and the search, with the test-only fields lent to contacts.
 */
final class ContactCustomFieldsTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private Fields $fields;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $this->fields = LentFields::lend(self::getContainer(), ContactExtension::RECORD);
        $this->browser->seed(role: Role::Member);
        $this->browser->em()->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->browser->em()->flush();
        $this->browser->signIn();
    }

    public function testTheListShowsFiltersAndSortsByTheFields(): void
    {
        $alpha = $this->contact('Alpha Example');
        $beta = $this->contact('Beta Example');
        $this->contact('Gamma Example');
        $this->fields->write(ContactExtension::RECORD, $alpha, ['test_fields_code' => 'Z-9', 'test_fields_seats' => 4]);
        $this->fields->write(ContactExtension::RECORD, $beta, ['test_fields_code' => 'A-1', 'test_fields_seats' => 12]);
        $this->browser->em()->flush();

        $seats = $this->rows('/contacts?cols=name,test_fields_code&sort=test_fields_code&f='.WorkingFilter::encode(ViewFilter::all(new Condition('test_fields_seats', 'gt', 2))));
        $descending = $this->rows('/contacts?cols=name,test_fields_code&sort=-test_fields_code');

        self::assertSame(['Beta Example A-1', 'Alpha Example Z-9'], $seats, 'By the code, the lower first.');
        // A contact without the value comes first or last, as the database orders nothing.
        self::assertSame(['Alpha Example Z-9', 'Beta Example A-1'], array_values(array_filter($descending, static fn (string $row): bool => !str_starts_with($row, 'Gamma'))));
    }

    public function testTheSearchReadsTheSearchableFields(): void
    {
        $alpha = $this->contact('Alpha Example');
        $this->contact('Beta Example');
        $this->fields->write(ContactExtension::RECORD, $alpha, ['test_fields_long_note' => 'Prefers deliveries before noon']);
        $this->browser->em()->flush();

        self::assertSame(['Alpha Example'], $this->rows('/contacts?cols=name&q=NOON'));
        self::assertSame([], $this->rows('/contacts?cols=name&q=evening'));
    }

    /**
     * @return list<string> the text of each row of the list, its spaces collapsed
     */
    private function rows(string $url): array
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler->filter('table tbody tr')->each(static fn ($row): string => trim((string) preg_replace('/\s+/', ' ', $row->text())));
    }

    private function contact(string $name): string
    {
        $contact = new Party(PartyKind::Person, $name, null, null, '');
        $this->browser->em()->persist($contact);
        $this->browser->em()->flush();

        return $contact->id();
    }
}
