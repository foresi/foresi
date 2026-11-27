<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Tests;

use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Contacts\Application\CreateParty;
use Foresi\Party\Contract\Field\ContactExtension;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The fleet's custom fields on the contact's screens (module API 1.4): the card
 * "Additional details" of the contact's form, saved through the contact's own use case
 * with the changed keys in contacts.contact.updated, the values on the detail by their
 * labels, and the history that names a field by its label.
 */
final class FleetFieldScreensTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $this->user = $this->browser->seed(role: Role::Member);
        // A firm, so the instance is past its first-run setup.
        $this->browser->em()->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->browser->em()->flush();
        $this->browser->signIn();
    }

    public function testTheContactFormAsksForTheFleetsFields(): void
    {
        $crawler = $this->client->request('GET', '/contacts/new');

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('main form [data-custom-fields]');
        self::assertStringContainsString('Additional details', $crawler->filter('main form')->text());
        self::assertSame(['', 'logistics', 'construction', 'retail'], $card->filter('select[name="custom_fields[acme_fleet_industry]"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame('Logistics', $card->filter('select[name="custom_fields[acme_fleet_industry]"] option[value="logistics"]')->text());
        self::assertSame('date', $card->filter('input[name="custom_fields[acme_fleet_contract_until]"]')->attr('type'));
        self::assertCount(3, $card->filter('input[type="checkbox"][name="custom_fields[acme_fleet_services][]"]'));
        self::assertCount(1, $card->filter('input[name="custom_fields_text[acme_fleet_fleet_manager]"]'));
        self::assertStringContainsString('Fleet manager', $card->text());
    }

    public function testSavingTheFormWritesTheFieldsAndTheEventNamesThem(): void
    {
        $contact = $this->contact('Example Haulage');
        $form = $this->client->request('GET', '/contacts/'.$contact.'/edit')->filter('main form')->form();
        $form['custom_fields[acme_fleet_industry]']->setValue('logistics');
        $form['custom_fields[acme_fleet_contract_until]']->setValue('2027-03-31');
        $form['custom_fields[acme_fleet_fleet_manager]']->setValue($this->user->id());
        $this->client->submit($form);

        self::assertResponseRedirects('/contacts/'.$contact);
        $row = $this->browser->em()->getConnection()->fetchAssociative('SELECT industry, contract_until, fleet_manager FROM acme_fleet_contact_extra WHERE id = ?', [$contact]);
        self::assertSame(['industry' => 'logistics', 'contract_until' => '2027-03-31', 'fleet_manager' => $this->user->id()], $row);
        self::assertSame(['acme_fleet_industry', 'acme_fleet_contract_until', 'acme_fleet_fleet_manager'], $this->updatedKeys($contact));
    }

    public function testTheDetailAndTheHistoryShowTheFieldsByTheirLabels(): void
    {
        $contact = $this->contact('Example Haulage');
        $fields = self::getContainer()->get(Fields::class);
        \assert($fields instanceof Fields);
        $fields->write(ContactExtension::RECORD, $contact, ['acme_fleet_industry' => 'construction', 'acme_fleet_services' => ['leasing', 'insurance'], 'acme_fleet_fleet_manager' => $this->user->id()]);
        $this->browser->em()->flush();

        $detail = $this->client->request('GET', '/contacts/'.$contact);
        $history = $this->client->request('GET', '/contacts/'.$contact.'?tab=history');

        self::assertResponseIsSuccessful();
        $card = $detail->filter('[data-custom-fields]')->text();
        self::assertStringContainsString('Industry', $card);
        self::assertStringContainsString('Construction', $card);
        self::assertStringContainsString('Leasing, Insurance', $card);
        self::assertStringContainsString('Example Company', $card, 'The fleet manager by name.');
        $text = $history->filter('main')->text();
        self::assertStringContainsString('Industry', $text);
        self::assertStringContainsString('Fleet manager', $text);
        self::assertStringNotContainsString('fleetManager', $text, 'Not by the property.');
    }

    private function contact(string $name): string
    {
        // The fixture is set up by the application, not by the signed-in person.
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $signedIn = $tokens->getToken();
        $tokens->setToken(null);
        $party = self::getContainer()->get(CreateParty::class)->create(PartyKind::Person, $name, null, null, '', '', [PartyRole::Customer], new Address('Example Street 1', 'Example City', '100 00', 'CZ'));
        $tokens->setToken($signedIn);

        return $party->id();
    }

    /**
     * @return list<string> the custom fields the last contacts.contact.updated of the contact names
     */
    private function updatedKeys(string $id): array
    {
        $data = $this->browser->em()->getConnection()->fetchOne(
            'SELECT '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? AND '.EventLogTable::SUBJECT_ID.' = ? ORDER BY '.EventLogTable::ID.' DESC',
            ['contacts.contact.updated', $id],
        );
        self::assertIsString($data);
        $payload = json_decode($data, true);
        self::assertIsArray($payload);
        $keys = $payload['customFields'] ?? $payload['custom_fields'] ?? null;
        self::assertIsArray($keys);

        return array_values(array_filter($keys, \is_string(...)));
    }
}
