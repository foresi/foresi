<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Application\FirmBankAccounts;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Form;

final class LegalEntityControllerTest extends WebTestCase
{
    private const string IBAN = 'CZ65 0800 0000 1920 0014 5399';
    private const string IBAN_STORED = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    public function testTheOwnerOpensTheFirmStraightAway(): void
    {
        $this->signIn();
        $this->firm('Example Firm s.r.o.', '12345695');

        $crawler = $this->client->request('GET', '/settings/firm');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Firm');
        self::assertSelectorNotExists('main table');
        self::assertSame('Example Firm s.r.o.', $crawler->filter('#name')->attr('value'));
        self::assertSame('12345695', $crawler->filter('#registrationNumber')->attr('value'));
        self::assertSelectorNotExists('#lookup');
    }

    public function testAnInstanceWithoutAFirmSetsItUpOnTheSamePage(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/settings/firm');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Set up the firm');
        self::assertSelectorExists('#lookup');
        self::assertSame('/settings/firm', $crawler->filter('main form[data-controller]')->attr('action'));
        self::assertSelectorNotExists('main nav[aria-label="Firm sections"]');
    }

    public function testTheSidebarOffersTheFirmToTheOwner(): void
    {
        $this->signIn();

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('#sidebar [data-nav-group="settings"]', 'Firm');
    }

    public function testTheSettingsGroupHoldsPeopleFirmLabelsApiAccessAndTheImportHistory(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/');

        $links = $crawler->filter('#sidebar [data-nav-group="settings"] a')->each(static fn ($a): string => $a->attr('href').' '.trim($a->text()));
        self::assertSame(['/settings/people People', '/settings/firm Firm', '/settings/labels Labels', '/settings/api API and MCP', '/settings/webhooks Webhooks', '/imports Import and export', '/settings/documents Documents'], $links);
    }

    public function testTheFirmPageHasTabsForDetailsInvoicingMailAndBankAndKeepsTheFirmMarkedInTheSidebar(): void
    {
        $this->signIn();
        $this->firm('Example Firm', '12345695');

        $crawler = $this->client->request('GET', '/settings/firm');

        self::assertSame(['Details', 'Invoicing', 'E-mail', 'Bank'], $crawler->filter('main nav[aria-label="Firm sections"] a')->each(static fn ($a): string => trim($a->text())));
        self::assertSame(['/settings/firm', '/settings/firm/invoicing', '/settings/firm/mail', '/settings/firm/bank'], $crawler->filter('main nav[aria-label="Firm sections"] a')->each(static fn ($a): string => (string) $a->attr('href')));
        self::assertSame('Details', trim($crawler->filter('main nav[aria-label="Firm sections"] [aria-current="page"]')->text()));
        self::assertSame('/settings/firm', $crawler->filter('#sidebar [aria-current="page"]')->attr('href'));

        $crawler = $this->client->request('GET', '/settings/firm/invoicing');

        self::assertResponseIsSuccessful();
        self::assertSame('Invoicing', trim($crawler->filter('main nav[aria-label="Firm sections"] [aria-current="page"]')->text()));
        self::assertSame('/settings/firm', $crawler->filter('#sidebar [aria-current="page"]')->attr('href'));
    }

    public function testTheSidebarHidesTheFirmFromOtherRoles(): void
    {
        $this->browser->seed(role: Role::Member);
        $this->browser->signIn();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#sidebar [data-nav-group="settings"] a[href="/settings/firm"]');
    }

    public function testTheScreensAreTranslated(): void
    {
        $this->signIn('cs');
        $this->firm('Example Firm', '12345695');

        $this->client->request('GET', '/settings/firm');

        self::assertSelectorTextContains('h1', 'Firma');
        self::assertSelectorTextContains('#sidebar [data-nav-group="settings"]', 'Firma');
    }

    public function testTheSetupFormOffersTheRegistryLookupOfTheContactForm(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/settings/firm');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('main form');
        self::assertSame('party-lookup', $form->attr('data-controller'));
        self::assertSame('/contacts/lookup', $form->attr('data-party-lookup-url-value'));
        foreach (['query', 'results', 'name', 'registrationCountry', 'registrationNumber', 'vatId', 'street', 'city', 'postalCode', 'countryCode'] as $target) {
            self::assertCount(1, $form->filter('[data-party-lookup-target="'.$target.'"]'), $target);
        }
        self::assertSelectorExists('main [data-action-bar]');
    }

    public function testTheOwnerSetsUpTheFirm(): void
    {
        $this->signIn();

        $this->submitNew([
            'name' => 'Example Company s.r.o.',
            'registrationNumber' => '1234 5687',
            'vatId' => 'cz 12345687',
            'registerEntry' => 'Register entry C 1234',
        ]);

        self::assertResponseRedirects('/settings/firm');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The firm is saved.');
        self::assertSame('Example Company s.r.o.', $crawler->filter('#name')->attr('value'));
        self::assertSelectorExists('main nav[aria-label="Firm sections"]');

        $firm = $this->storedFirm();
        self::assertSame('12345687', $firm->registrationNumber()->value);
        self::assertSame('CZ12345687', $firm->vatId()?->toString());
        self::assertSame(VatStatus::Registered, $firm->vatStatus());
        self::assertSame('Register entry C 1234', $firm->registerEntry());
        self::assertSame('Example City', $firm->address()->city);
        self::assertSame([], $this->accounts()->all(), 'the firm form opens no bank account');
    }

    public function testANonPayerFirmSavesWithoutAVatNumber(): void
    {
        $this->signIn();

        $this->submitNew(['name' => 'Example Trader', 'vatStatus' => 'not_registered', 'vatId' => '']);

        self::assertResponseRedirects('/settings/firm');
        $firm = $this->storedFirm();
        self::assertSame(VatStatus::NotRegistered, $firm->vatStatus());
        self::assertNull($firm->vatId());
    }

    public function testAVatPayerFirmNeedsAVatNumber(): void
    {
        $this->signIn();

        foreach (['registered', 'identified_person'] as $status) {
            $this->submitNew(['vatStatus' => $status, 'vatId' => '']);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('main', 'Enter the VAT ID of this firm.');
        }
        self::assertSame(0, $this->firmCount());
    }

    public function testMistakesAreShownAtTheirFieldsWithTheValuesKept(): void
    {
        $this->signIn();

        $this->submitNew(['name' => '', 'registrationNumber' => '', 'vatId' => '123', 'street' => '', 'vatStatus' => 'nonsense']);

        self::assertResponseStatusCodeSame(422);
        foreach (['name', 'registrationNumber', 'vatId', 'street', 'vatStatus'] as $field) {
            self::assertSelectorExists('#'.$field.'[aria-invalid="true"]');
        }
        self::assertSame(0, $this->firmCount());
    }

    public function testAnInvalidRegistrationNumberIsAFormError(): void
    {
        $this->signIn();

        $this->submitNew(['registrationNumber' => '12345678']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#registrationNumber[aria-invalid="true"]');
    }

    public function testSubmittingTheSameSetupFormTwiceCreatesOneFirm(): void
    {
        $this->signIn();
        $form = $this->newForm();

        $this->client->submit($form);
        self::assertResponseRedirects('/settings/firm');
        $this->client->submit($form);

        self::assertResponseRedirects('/settings/firm');
        $this->client->followRedirect();
        self::assertSelectorTextNotContains('main', 'Foresi keeps one firm per instance.');
        self::assertSame(1, $this->firmCount());
    }

    public function testAFormWithAnUnknownSubmissionTokenIsShownAgain(): void
    {
        $this->signIn();

        $this->client->submit($this->newForm(), ['_submission' => 'unknown']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'The form has expired.');
        self::assertSame(0, $this->firmCount());
    }

    public function testTheOwnerEditsTheFirm(): void
    {
        $this->signIn();
        $this->firm('Example Firm', '12345695');
        FirmAccounts::add($this->em(), self::IBAN);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/settings/firm');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#lookup');
        self::assertSelectorNotExists('form[action$="/archive"]');
        self::assertSame('12345695', $crawler->filter('#registrationNumber')->attr('value'));
        self::assertSelectorNotExists('#bankAccounts');
        self::assertSelectorTextContains('#bank-accounts-note', 'The firm\'s bank accounts are kept in its bank settings.');
        self::assertSame('/settings/firm/bank', $crawler->filter('#bank-accounts-note a')->attr('href'));

        $this->client->submit($crawler->filter('main form[data-controller]')->form(['name' => 'Renamed Firm', 'vatStatus' => 'not_registered', 'vatId' => '']));

        self::assertResponseRedirects('/settings/firm');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The changes are saved.');
        $stored = $this->storedFirm();
        self::assertSame('Renamed Firm', $stored->name());
        self::assertSame([self::IBAN_STORED], $this->accounts()->ibans(), 'saving the firm keeps its accounts');
        self::assertSame('12345695', $stored->registrationNumber()->value);
    }

    #[DataProvider('otherRoles')]
    public function testOtherRolesAreRefused(Role $role): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();

        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, '/settings/firm');

            self::assertResponseStatusCodeSame(403, $method);
        }
        $this->firm('Example Firm', '12345695');
        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, '/settings/firm');

            self::assertResponseStatusCodeSame(403, $method.' with a firm');
        }
    }

    /**
     * @return iterable<string, array{Role}>
     */
    public static function otherRoles(): iterable
    {
        yield 'member' => [Role::Member];
        yield 'guest' => [Role::Guest];
    }

    public function testASetupFormFromBeforeTheFirmWasSetUpAddsNoSecondFirm(): void
    {
        $this->signIn();
        $form = $this->newForm();
        // Another tab or request sets up the firm between the form and its submission.
        $this->firm('Example Firm', '12345695');

        $this->client->submit($form);

        self::assertResponseRedirects('/settings/firm');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Foresi keeps one firm per instance.');
        self::assertSame(1, $this->firmCount());
        self::assertSame('Example Firm', $this->storedFirm()->name());
    }

    public function testTheFirmIsNotArchivedFromItsPage(): void
    {
        $this->signIn();
        $this->firm('Example Firm', '12345695');

        $crawler = $this->client->request('GET', '/settings/firm');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action$="/archive"]'));
        self::assertSelectorNotExists('#firm-actions');
    }

    public function testAForgedSaveIsRefused(): void
    {
        $this->signIn();

        $this->client->request('POST', '/settings/firm', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $this->firm('Example Firm', '12345695');
        $this->client->request('POST', '/settings/firm', ['_token' => 'forged', 'name' => 'Forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Example Firm', $this->storedFirm()->name());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function oldAddresses(): iterable
    {
        $id = '019a0000-0000-7000-8000-000000000001';
        yield 'the list' => ['GET', '/settings/firms', '/settings/firm'];
        yield 'the archived view' => ['GET', '/settings/firms?view=archived', '/settings/firm?view=archived'];
        yield 'the new-firm screen' => ['GET', '/settings/firms/new', '/settings/firm'];
        yield 'the form' => ['GET', '/settings/firms/'.$id.'/edit', '/settings/firm'];
        yield 'a tab with its section' => ['GET', '/settings/firms/'.$id.'/bank?tab=pairing', '/settings/firm/bank?tab=pairing'];
        yield 'a series' => ['GET', '/settings/firms/'.$id.'/invoicing/series/new', '/settings/firm/invoicing/series/new'];
        yield 'a form sent from an old page' => ['POST', '/settings/firms/'.$id.'/mail', '/settings/firm/mail'];
    }

    #[DataProvider('oldAddresses')]
    public function testTheOldAddressesLeadToTheFirmPage(string $method, string $old, string $new): void
    {
        $this->signIn();

        $this->client->request($method, $old);

        self::assertResponseStatusCodeSame(308);
        self::assertResponseRedirects($new);
    }

    private function signIn(string $locale = 'en'): void
    {
        $this->browser->seed($locale);
        $this->browser->signIn();
    }

    private function firm(string $name, string $number): LegalEntity
    {
        $em = $this->em();
        $firm = new LegalEntity($name, new RegistrationNumber('CZ', $number), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $em->persist($firm);
        $em->flush();

        return $firm;
    }

    private function newForm(): Form
    {
        $crawler = $this->client->request('GET', '/settings/firm');

        return $crawler->filter('main form')->form([
            'name' => 'Example Company s.r.o.',
            'registrationCountry' => 'CZ',
            'registrationNumber' => '12345687',
            'vatStatus' => 'registered',
            'vatId' => 'CZ12345687',
            'street' => 'Example Street 1',
            'city' => 'Example City',
            'postalCode' => '100 00',
            'countryCode' => 'CZ',
        ]);
    }

    /**
     * @param array<string, string> $values
     */
    private function submitNew(array $values = []): void
    {
        $form = $this->newForm();
        // Posted directly, to send what the select does not offer.
        $this->client->request('POST', '/settings/firm', $values + $form->getPhpValues());
    }

    private function storedFirm(): LegalEntity
    {
        $em = $this->em();
        $em->clear();

        $firms = $em->getRepository(LegalEntity::class)->findAll();
        self::assertCount(1, $firms);

        return $firms[0];
    }

    private function firmCount(): int
    {
        return \count($this->em()->getConnection()->fetchFirstColumn('SELECT id FROM legal_entity'));
    }

    private function accounts(): FirmBankAccounts
    {
        return self::getContainer()->get(FirmBankAccounts::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
