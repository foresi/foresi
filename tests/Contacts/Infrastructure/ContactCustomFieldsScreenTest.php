<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Contacts\Infrastructure;

use Foresi\Contacts\Application\CreateParty;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Application\PartyInput;
use Foresi\Party\Contract\Field\ContactExtension;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The custom fields of a contact (ADR 0045) on its screens, with the test-only fields
 * lent to contacts and their code required: the card "Additional details" of the whole
 * form, the required ones in the short dialog, the save through the contact's use case
 * with the changed keys in contacts.contact.updated, and the values on the detail.
 */
final class ContactCustomFieldsScreenTest extends WebTestCase
{
    private const array DIALOG = ['HTTP_X_FORESI_DIALOG' => '1'];

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private Fields $fields;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $this->fields = LentFields::lend(self::getContainer(), ContactExtension::RECORD, ['test_fields_code']);
        $this->browser->seed(role: Role::Member);
        $this->browser->em()->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->browser->em()->flush();
        $this->browser->signIn();
    }

    public function testTheWritesAskForTheRequiredFieldOfANewContact(): void
    {
        $this->expectExceptionObject(new FieldRefused('test_fields_code', FieldRefused::REQUIRED));

        self::getContainer()->get(CreateParty::class)->createFrom(new PartyInput(PartyKind::Person, 'Example Person', null, null, '', '', [PartyRole::Customer], new Address('Example Street 1', 'Example City', '100 00', 'CZ'), customFields: []));
    }

    public function testTheWholeFormAsksForEveryFieldByItsKind(): void
    {
        $crawler = $this->client->request('GET', '/contacts/new');

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('main form [data-custom-fields]');
        self::assertCount(1, $card);
        self::assertStringContainsString('Additional details', $crawler->filter('main form')->text());
        self::assertSame('text', $card->filter('input[name="custom_fields[test_fields_code]"]')->attr('type'));
        self::assertNotNull($card->filter('input[name="custom_fields[test_fields_code]"]')->attr('required'), 'A new contact needs its required field.');
        self::assertSame('decimal', $card->filter('input[name="custom_fields[test_fields_seats]"]')->attr('inputmode'));
        self::assertSame('date', $card->filter('input[name="custom_fields[test_fields_due]"]')->attr('type'));
        self::assertSame('datetime-local', $card->filter('input[name="custom_fields[test_fields_seen_at]"]')->attr('type'));
        self::assertSame('url', $card->filter('input[name="custom_fields[test_fields_site]"]')->attr('type'));
        self::assertCount(1, $card->filter('textarea[name="custom_fields[test_fields_long_note]"]'));
        self::assertSame(['', 'small', 'large'], $card->filter('select[name="custom_fields[test_fields_size]"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['red', 'blue'], $card->filter('input[type="checkbox"][name="custom_fields[test_fields_tags][]"]')->each(static fn (Crawler $box): string => (string) $box->attr('value')));
        self::assertCount(1, $card->filter('input[type="checkbox"][name="custom_fields[test_fields_flag]"]'));
        self::assertSame('/fields/contact/matches', $card->filter('[data-custom-field="test_fields_contact"] [data-combobox-url-value]')->attr('data-combobox-url-value'));
        self::assertCount(1, $card->filter('input[name="custom_fields_text[test_fields_contact]"]'));
        self::assertSame('/fields/user/matches', $card->filter('[data-custom-field="test_fields_owner"] [data-combobox-url-value]')->attr('data-combobox-url-value'));
    }

    public function testANewContactNeedsItsRequiredFieldAndKeepsTheOthers(): void
    {
        $crawler = $this->client->request('GET', '/contacts/new');
        $tokens = ['_token' => (string) $crawler->filter('main form input[name="_token"]')->attr('value'), '_submission' => (string) $crawler->filter('main form input[name="_submission"]')->attr('value')];
        $custom = ['test_fields_seats' => '12'];

        $crawler = $this->client->request('POST', '/contacts/new', $this->contactValues() + ['custom_fields' => $custom] + $tokens);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#custom-field-test-fields-code')->attr('aria-invalid'));
        self::assertSame('12', $crawler->filter('#custom-field-test-fields-seats')->attr('value'), 'What was typed shows again.');

        $custom['test_fields_code'] = 'N-1';
        $this->client->request('POST', '/contacts/new', $this->contactValues() + ['custom_fields' => $custom] + $tokens);

        self::assertResponseRedirects();
        $id = substr((string) $this->client->getResponse()->headers->get('Location'), \strlen('/contacts/'));
        self::assertSame('N-1', $this->fields->value(ContactExtension::RECORD, $id, 'test_fields_code'));
        self::assertEquals(12, $this->fields->value(ContactExtension::RECORD, $id, 'test_fields_seats'));
    }

    public function testEditingSavesTheFieldsAndTheEventNamesTheChangedOnes(): void
    {
        $id = $this->contact('Example Haulage');
        $this->fields->write(ContactExtension::RECORD, $id, ['test_fields_code' => 'A-1', 'test_fields_size' => 'small']);
        $this->browser->em()->flush();

        $crawler = $this->client->request('GET', '/contacts/'.$id.'/edit');
        self::assertSame('A-1', $crawler->filter('#custom-field-test-fields-code')->attr('value'));
        self::assertNull($crawler->filter('#custom-field-test-fields-code')->attr('required'), 'Only a new contact is asked for it.');
        $form = $crawler->filter('main form')->form();
        $values = $form->getPhpValues();
        $values['custom_fields'] = ['test_fields_size' => 'large', 'test_fields_tags' => ['red']] + (\is_array($values['custom_fields'] ?? null) ? $values['custom_fields'] : []);
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects('/contacts/'.$id);
        self::assertSame('large', $this->fields->value(ContactExtension::RECORD, $id, 'test_fields_size'));
        self::assertSame(['red'], $this->fields->value(ContactExtension::RECORD, $id, 'test_fields_tags'));
        self::assertSame(['test_fields_size', 'test_fields_tags'], $this->updatedKeys($id));
    }

    public function testAValueTheFieldDoesNotTakeIsToldOnTheField(): void
    {
        $id = $this->contact('Example Haulage');
        $crawler = $this->client->request('GET', '/contacts/'.$id.'/edit');
        $form = $crawler->filter('main form')->form();
        $values = $form->getPhpValues();
        $values['custom_fields'] = ['test_fields_seats' => '2.5'] + (\is_array($values['custom_fields'] ?? null) ? $values['custom_fields'] : []);

        $crawler = $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#custom-field-test-fields-seats')->attr('aria-invalid'), 'A whole number is refused by the write and shown on its field.');
        self::assertSame('This value cannot be saved.', trim($crawler->filter('#custom-field-test-fields-seats-error')->text()));
    }

    public function testTheShortDialogAsksOnlyForTheRequiredFields(): void
    {
        $this->client->request('GET', '/contacts/new?for=contacts', server: self::DIALOG);
        $crawler = new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost/');

        self::assertSame(['custom_fields[test_fields_code]'], $crawler->filter('form#contact-quick-form [name^="custom_fields"]')->each(static fn (Crawler $input): string => (string) $input->attr('name')));
    }

    public function testTheDetailShowsTheValuesAsAPersonReadsThem(): void
    {
        $id = $this->contact('Example Haulage');
        $other = $this->contact('Example Builders');
        $this->fields->write(ContactExtension::RECORD, $id, [
            'test_fields_code' => 'A-1', 'test_fields_size' => 'large', 'test_fields_tags' => ['red', 'blue'], 'test_fields_flag' => true,
            'test_fields_due' => new \DateTimeImmutable('2027-01-31'), 'test_fields_contact' => $other,
        ]);
        $this->browser->em()->flush();

        $crawler = $this->client->request('GET', '/contacts/'.$id);

        self::assertResponseIsSuccessful();
        $rows = [];
        foreach ($crawler->filter('[data-custom-fields] dt') as $index => $term) {
            $rows[trim($term->textContent)] = trim($crawler->filter('[data-custom-fields] dd')->eq($index)->text());
        }
        self::assertSame('A-1', $rows['test.field.code']);
        self::assertSame('test.size.large', $rows['test.field.size'], 'An option shows by its label.');
        self::assertSame('test.tag.red, test.tag.blue', $rows['test.field.tags']);
        self::assertSame('Yes', $rows['test.field.flag']);
        self::assertSame('Example Builders', $rows['test.field.contact']);
        self::assertSame('/contacts/'.$other, $crawler->filter('[data-custom-fields] a:contains("Example Builders")')->attr('href'));
        self::assertSame('Not set', $rows['test.field.site']);
    }

    public function testThePickerOffersTheContactsAndThePeople(): void
    {
        $id = $this->contact('Example Haulage');
        $this->contact('Other Builders');

        $this->client->request('GET', '/fields/contact/matches?q=haul');
        $contacts = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->client->request('GET', '/fields/user/matches?q=example');
        $people = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(['results' => [['id' => $id, 'label' => 'Example Haulage', 'hint' => null]]], $contacts);
        self::assertIsArray($people);
        self::assertIsArray($people['results']);
        self::assertSame(['Example Company'], array_column($people['results'], 'label'));
    }

    public function testTheFilterSheetPicksAContactFieldFromThePicker(): void
    {
        $this->contact('Example Haulage');
        $crawler = $this->client->request('GET', '/contacts');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/fields/contact/matches', $crawler->filter('main')->html());
    }

    /**
     * @return array<string, mixed>
     */
    private function contactValues(): array
    {
        return ['kind' => 'person', 'displayName' => 'Example Person', 'registrationCountry' => 'CZ', 'registrationNumber' => '', 'vatId' => '', 'email' => '', 'phone' => '', 'roles' => ['customer'], 'street' => 'Example Street 1', 'city' => 'Example City', 'postalCode' => '100 00', 'countryCode' => 'CZ', 'privateNote' => ''];
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
