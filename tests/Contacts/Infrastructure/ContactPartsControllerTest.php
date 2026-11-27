<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Contacts\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Contacts\Application\CreateParty;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\ContactLink;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyBankAccount;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The tabs of a contact add their contact persons, bank accounts and addresses
 * in dialogs, which are pages too, and change them with row actions.
 * The tests that need the Czech rules are in Integration/Country.
 */
final class ContactPartsControllerTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    public function testEachTabOffersToAddInADialogThatFollowsToTheTab(): void
    {
        $this->signIn();
        $company = $this->party('Example Company');

        foreach (['contacts' => 'persons/new', 'bank-accounts' => 'bank-accounts/new', 'addresses' => 'addresses/new'] as $tab => $path) {
            $crawler = $this->client->request('GET', '/contacts/'.$company->id().'?tab='.$tab);
            $add = $crawler->filter('main a[href="/contacts/'.$company->id().'/'.$path.'"]');
            self::assertGreaterThan(0, $add->count(), $tab);
            self::assertNotNull($add->attr('data-dialog'), $tab);
            self::assertSame('follow', $add->attr('data-dialog-then'), $tab);
        }
        // The old form under the table is gone.
        self::assertSelectorNotExists('form[action$="/link"]');
    }

    public function testANewPersonIsCreatedAndLinkedInOneGo(): void
    {
        $this->signIn();
        $company = $this->party('Example Company');

        // With nobody in the address book to choose, the dialog starts with a new person.
        $crawler = $this->client->request('GET', '/contacts/'.$company->id().'/persons/new');
        self::assertSame('new', $crawler->filter('input[name="mode"]')->attr('value'));
        $form = $crawler->filter('#contact-person-form')->form(['name' => 'Alex Example', 'email' => 'alex@example.com', 'phone' => '+420 000 000 001', 'position' => 'Accountant']);
        // The first contact person is the primary one unless unticked.
        $primary = $form['primary'];
        self::assertInstanceOf(ChoiceFormField::class, $primary);
        self::assertSame('1', $primary->getValue());
        $this->client->submit($form);

        self::assertResponseRedirects('/contacts/'.$company->id().'?tab=contacts');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main table', 'Alex Example');
        self::assertSelectorTextContains('main table', 'Accountant');
        self::assertSelectorTextContains('main table', 'Primary');
        self::assertSelectorTextContains('main', 'The person is created and added as a contact person.');

        $person = $this->em()->getRepository(Party::class)->findOneBy(['displayName' => 'Alex Example']) ?? throw new \LogicException();
        self::assertSame(PartyKind::Person, $person->kind());
        self::assertSame('+420 000 000 001', $person->phone());
    }

    public function testANewPersonNeedsANameAndKeepsWhatWasEntered(): void
    {
        $this->signIn();
        $company = $this->party('Example Company');
        $crawler = $this->client->request('GET', '/contacts/'.$company->id().'/persons/new?mode=new');

        $crawler = $this->client->submit($crawler->filter('#contact-person-form')->form(['name' => '', 'email' => 'not an e-mail', 'position' => 'Accountant']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['name', 'email'], $crawler->filter('[aria-invalid="true"]')->each(static fn (Crawler $control): string => (string) $control->attr('id')));
        self::assertSame('Accountant', $crawler->filter('#position')->attr('value'));
        self::assertSame(1, $this->rows(Party::class));
    }

    public function testAPersonFromTheAddressBookIsLinkedOnce(): void
    {
        $this->signIn();
        $company = $this->party('Example Company');
        $person = $this->party('Test Person', PartyKind::Person);

        $crawler = $this->client->request('GET', '/contacts/'.$company->id().'/persons/new');
        self::assertSame('existing', $crawler->filter('input[name="mode"]')->attr('value'));
        // Both ways are offered as tabs of the dialog.
        self::assertCount(2, $crawler->filter('[data-person-modes] a'));
        $this->client->submit($crawler->filter('#contact-person-form')->form(['person' => $person->id(), 'position' => 'Director']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main table', 'Test Person');
        self::assertSelectorTextContains('main', 'The contact person is added.');

        // Linked already, so no longer offered; a hand-made request is refused at the field.
        $this->client->request('GET', '/contacts/'.$company->id().'/persons/new?mode=existing');
        self::assertSelectorExists('[data-no-candidates]');
        $this->client->request('POST', '/contacts/'.$company->id().'/persons/new', ['_token' => $this->formToken($company), 'mode' => 'existing', 'person' => $person->id()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-link-error]', 'Choose a person who is not listed for the company yet.');
        // An id that is not one is refused the same way, never reaching the database as a uuid.
        $this->client->request('POST', '/contacts/'.$company->id().'/persons/new', ['_token' => $this->formToken($company), 'mode' => 'existing', 'person' => '12']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAContactPersonIsMadePrimaryAndUnlinked(): void
    {
        $this->signIn();
        $company = $this->party('Example Company');
        foreach (['First Person', 'Second Person'] as $name) {
            $crawler = $this->client->request('GET', '/contacts/'.$company->id().'/persons/new?mode=new');
            $this->client->submit($crawler->filter('#contact-person-form')->form(['name' => $name]));
        }
        $second = $this->link($company, 'Second Person');
        self::assertFalse($second->isPrimary());

        $this->client->request('POST', '/contacts/'.$company->id().'/persons/'.$second->id().'/primary', ['_token' => $this->actionToken()]);
        self::assertResponseRedirects('/contacts/'.$company->id().'?tab=contacts');
        self::assertTrue($this->link($company, 'Second Person')->isPrimary());
        self::assertFalse($this->link($company, 'First Person')->isPrimary());

        // The unlink asks first, in the overlay too.
        $crawler = $this->client->request('GET', '/contacts/'.$company->id().'?tab=contacts');
        $unlink = $crawler->filter('form[action="/contacts/'.$company->id().'/persons/'.$second->id().'/unlink"]');
        self::assertSame('confirm-submit', $unlink->attr('data-controller'));
        self::assertSame('Remove Second Person from the company?', $unlink->attr('data-confirm-title'));

        $this->client->request('POST', '/contacts/'.$company->id().'/persons/'.$second->id().'/unlink', ['_token' => $this->actionToken()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Second Person is no longer a contact person of the company.');
        self::assertSelectorTextNotContains('main table', 'Second Person');
        // The person stays in the address book.
        self::assertNotNull($this->em()->getRepository(Party::class)->findOneBy(['displayName' => 'Second Person']));
    }

    public function testAPersonHasNoContactPersonsToAdd(): void
    {
        $this->signIn();
        $person = $this->party('Test Person', PartyKind::Person);

        $this->client->request('GET', '/contacts/'.$person->id().'?tab=contacts');
        self::assertSelectorNotExists('#contact-person-add');
        $this->client->request('GET', '/contacts/'.$person->id().'/persons/new');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnAccountIsEditedMadePrimaryAndDeleted(): void
    {
        $this->signIn();
        $company = $this->party('Example Company');
        foreach ([self::IBAN, 'DE89370400440532013000'] as $number) {
            $crawler = $this->client->request('GET', '/contacts/'.$company->id().'/bank-accounts/new');
            $this->client->submit($crawler->filter('#contact-account-form')->form(['number' => $number]));
        }
        $first = $this->account(self::IBAN);
        $second = $this->account('DE89370400440532013000');
        self::assertTrue($first->isPrimary());
        self::assertFalse($second->isPrimary());

        // The number is shown, not edited; the label and the BIC change.
        $crawler = $this->client->request('GET', '/contacts/'.$company->id().'/bank-accounts/'.$second->id().'/edit');
        self::assertSelectorNotExists('input[name="number"]');
        $this->client->submit($crawler->filter('#contact-account-form')->form(['label' => 'Euro', 'bic' => 'COBADEFF']));
        self::assertSame('Euro', $this->account('DE89370400440532013000')->label());

        $this->client->request('POST', '/contacts/'.$company->id().'/bank-accounts/'.$second->id().'/primary', ['_token' => $this->actionToken()]);
        self::assertTrue($this->account('DE89370400440532013000')->isPrimary());
        self::assertFalse($this->account(self::IBAN)->isPrimary());

        // Deleting the primary account hands it to the next one.
        $this->client->request('POST', '/contacts/'.$company->id().'/bank-accounts/'.$second->id().'/delete', ['_token' => $this->actionToken()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The bank account is deleted.');
        self::assertSame(1, $this->rows(PartyBankAccount::class));
        self::assertTrue($this->account(self::IBAN)->isPrimary());

        // An account of another contact is not found here.
        $other = $this->party('Other Company');
        $this->client->request('GET', '/contacts/'.$other->id().'/bank-accounts/'.$first->id().'/edit');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnAddressIsAddedEditedAndDeletedButTheBillingOneStays(): void
    {
        $this->signIn();
        $company = $this->party('Example Company');

        $crawler = $this->client->request('GET', '/contacts/'.$company->id().'/addresses/new');
        // The billing address is never added; the country starts as the billing one's.
        self::assertCount(0, $crawler->filter('#type option[value="billing"]'));
        self::assertSame('CZ', $crawler->filter('#countryCode option[selected]')->attr('value'));
        $this->client->submit($crawler->filter('#contact-address-form')->form(['type' => 'delivery', 'label' => 'Warehouse', 'street' => 'Depot Road 5', 'city' => 'Example Town', 'postalCode' => '200 00']));
        self::assertResponseRedirects('/contacts/'.$company->id().'?tab=addresses');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main table', 'Warehouse');
        self::assertSelectorTextContains('main table', 'Depot Road 5');

        $delivery = $this->em()->getRepository(PartyAddress::class)->findOneBy(['label' => 'Warehouse']) ?? throw new \LogicException();
        $crawler = $this->client->request('GET', '/contacts/'.$company->id().'/addresses/'.$delivery->id().'/edit');
        $crawler = $this->client->submit($crawler->filter('#contact-address-form')->form(['street' => '', 'city' => 'Other Town']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['street'], $crawler->filter('[aria-invalid="true"]')->each(static fn (Crawler $control): string => (string) $control->attr('id')));
        $this->client->submit($crawler->filter('#contact-address-form')->form(['street' => 'Depot Road 7']));
        $this->em()->clear();
        self::assertSame('Depot Road 7', ($this->em()->find(PartyAddress::class, $delivery->id()) ?? throw new \LogicException())->address()->street);

        // The billing address is edited, never deleted.
        $billing = $this->em()->getRepository(PartyAddress::class)->findOneBy(['label' => '']) ?? throw new \LogicException();
        $crawler = $this->client->request('GET', '/contacts/'.$company->id().'?tab=addresses');
        self::assertCount(0, $crawler->filter('form[action="/contacts/'.$company->id().'/addresses/'.$billing->id().'/delete"]'));
        $this->client->request('POST', '/contacts/'.$company->id().'/addresses/'.$billing->id().'/delete', ['_token' => $this->actionToken()]);
        self::assertSame(2, $this->rows(PartyAddress::class));

        $this->client->request('POST', '/contacts/'.$company->id().'/addresses/'.$delivery->id().'/delete', ['_token' => $this->actionToken()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The address is deleted.');
        self::assertSame(1, $this->rows(PartyAddress::class));
    }

    public function testAGuestChangesNothing(): void
    {
        $this->signIn(Role::Guest);
        $company = $this->party('Example Company');

        foreach (['persons/new', 'bank-accounts/new', 'addresses/new'] as $path) {
            $this->client->request('GET', '/contacts/'.$company->id().'/'.$path);
            self::assertResponseStatusCodeSame(403, $path);
        }
    }

    private function signIn(Role $role = Role::Owner): void
    {
        $this->browser->seed('en', $role);
        $this->browser->signIn();
    }

    private function party(string $name, PartyKind $kind = PartyKind::SoleTrader): Party
    {
        // The fixture is set up by the application, not by the signed-in person.
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $signedIn = $tokens->getToken();
        $tokens->setToken(null);
        $party = self::getContainer()->get(CreateParty::class)->create($kind, $name, null, null, '', '', PartyKind::Person === $kind ? [] : [PartyRole::Customer], new Address('Example Street 1', 'Example City', '100 00', 'CZ'));
        $tokens->setToken($signedIn);

        return $party;
    }

    private function link(Party $company, string $name): ContactLink
    {
        $this->em()->clear();
        foreach ($this->em()->getRepository(ContactLink::class)->findAll() as $link) {
            if ($link->company()->id() === $company->id() && $link->person()->displayName() === $name) {
                return $link;
            }
        }

        throw new \LogicException('No link to '.$name.'.');
    }

    private function account(string $iban): PartyBankAccount
    {
        $this->em()->clear();

        return $this->em()->getRepository(PartyBankAccount::class)->findOneBy(['iban' => $iban]) ?? throw new \LogicException('No account '.$iban.'.');
    }

    /**
     * @param class-string $class
     */
    private function rows(string $class): int
    {
        return (int) $this->em()->createQuery('SELECT COUNT(e.id) FROM '.$class.' e')->getSingleScalarResult();
    }

    private function formToken(Party $company): string
    {
        $crawler = $this->client->request('GET', '/contacts/'.$company->id().'/persons/new?mode=new');

        return (string) $crawler->filter('#contact-person-form input[name="_token"]')->attr('value');
    }

    private function actionToken(): string
    {
        // The archive form of the list carries the token of the page actions.
        $crawler = $this->client->request('GET', '/contacts');

        return (string) $crawler->filter('form[action="/contacts/archive"] input[name="_token"]')->attr('value');
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
