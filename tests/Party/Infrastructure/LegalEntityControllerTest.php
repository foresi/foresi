<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure;

use App\Identity\Domain\Role;
use App\Party\Application\LegalEntities;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Tests\Party\Infrastructure\Support\RacingLegalEntities;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\TransactionRequiredException;
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
    private TenantContext $context;
    private TenantId $tenant;
    private TenantId $foreign;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), $this->context);
    }

    protected function tearDown(): void
    {
        RacingLegalEntities::$blind = false;
        parent::tearDown();
    }

    public function testTheOwnerListsTheFirmsOfTheActiveTenantOnly(): void
    {
        $this->signIn();
        $this->firm('Example Firm s.r.o.', $this->tenant, '12345695');
        $this->firm('Foreign Firm a.s.', $this->foreign, '12345687');

        $this->client->request('GET', '/settings/firms');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Firms');
        self::assertSelectorTextContains('main table', 'Example Firm s.r.o.');
        self::assertSelectorTextContains('main table', '12345695');
        self::assertSelectorTextNotContains('body', 'Foreign Firm');
    }

    public function testATenantWithoutFirmsIsToldHowToStart(): void
    {
        $this->signIn();

        $this->client->request('GET', '/settings/firms');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('main table');
        self::assertSelectorExists('main a[href="/settings/firms/new"]');
    }

    public function testTheSidebarOffersFirmsToTheOwner(): void
    {
        $this->signIn();

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('#sidebar [data-nav-group="settings"]', 'Firms');
    }

    public function testTheSettingsGroupHoldsFirmsAndTheImportHistory(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/');

        $links = $crawler->filter('#sidebar [data-nav-group="settings"] a')->each(static fn ($a): string => $a->attr('href').' '.trim($a->text()));
        self::assertSame(['/settings/firms Firms', '/imports Import and export'], $links);
    }

    public function testTheFirmPageHasTabsForDetailsAndInvoicingAndKeepsFirmsMarkedInTheSidebar(): void
    {
        $this->signIn();
        $id = $this->firm('Example Firm', $this->tenant, '12345695')->id();

        $crawler = $this->client->request('GET', '/settings/firms/'.$id.'/edit');

        self::assertSame(['Details', 'Invoicing'], $crawler->filter('main nav[aria-label="Firm sections"] a')->each(static fn ($a): string => trim($a->text())));
        self::assertSame('Details', trim($crawler->filter('main nav[aria-label="Firm sections"] [aria-current="page"]')->text()));
        self::assertSame('/settings/firms', $crawler->filter('#sidebar [aria-current="page"]')->attr('href'));

        $crawler = $this->client->request('GET', '/settings/firms/'.$id.'/invoicing');

        self::assertResponseIsSuccessful();
        self::assertSame('Invoicing', trim($crawler->filter('main nav[aria-label="Firm sections"] [aria-current="page"]')->text()));
        self::assertSame('/settings/firms', $crawler->filter('#sidebar [aria-current="page"]')->attr('href'));
    }

    public function testANewFirmHasNoTabs(): void
    {
        $this->signIn();

        $this->client->request('GET', '/settings/firms/new');

        self::assertSelectorNotExists('main nav[aria-label="Firm sections"]');
    }

    public function testTheSidebarHidesFirmsFromOtherRoles(): void
    {
        $this->browser->seed(['Tenant A'], role: Role::Member);
        $this->browser->signIn();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#sidebar [data-nav-group="settings"] a[href="/settings/firms"]');
    }

    public function testTheScreensAreTranslated(): void
    {
        $this->signIn('cs');

        $this->client->request('GET', '/settings/firms');

        self::assertSelectorTextContains('h1', 'Firmy');
        self::assertSelectorTextContains('#sidebar', 'Firmy');
    }

    public function testTheFormOffersTheRegistryLookupOfTheContactForm(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/settings/firms/new');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('main form');
        self::assertSame('party-lookup', $form->attr('data-controller'));
        self::assertSame('/contacts/lookup', $form->attr('data-party-lookup-url-value'));
        foreach (['query', 'results', 'name', 'registrationCountry', 'registrationNumber', 'vatId', 'street', 'city', 'postalCode', 'countryCode'] as $target) {
            self::assertCount(1, $form->filter('[data-party-lookup-target="'.$target.'"]'), $target);
        }
        self::assertSelectorExists('main [data-action-bar]');
    }

    public function testTheOwnerCreatesAFirm(): void
    {
        $this->signIn();

        $this->submitNew([
            'name' => 'Example Company s.r.o.',
            'registrationNumber' => '1234 5687',
            'vatId' => 'cz 12345687',
            'registerEntry' => 'Register entry C 1234',
            'bankAccounts' => self::IBAN."\n\n",
        ]);

        self::assertResponseRedirects('/settings/firms');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The firm is saved.');
        self::assertSelectorTextContains('main table', 'Example Company s.r.o.');

        $firm = $this->storedFirm();
        self::assertSame('12345687', $firm->registrationNumber()->value);
        self::assertSame('CZ12345687', $firm->vatId()?->toString());
        self::assertSame(VatStatus::Registered, $firm->vatStatus());
        self::assertSame('Register entry C 1234', $firm->registerEntry());
        self::assertSame([self::IBAN_STORED], $firm->bankAccounts());
        self::assertSame('Example City', $firm->address()->city);
    }

    public function testANonPayerFirmSavesWithoutAVatNumber(): void
    {
        $this->signIn();

        $this->submitNew(['name' => 'Example Trader', 'vatStatus' => 'not_registered', 'vatId' => '']);

        self::assertResponseRedirects('/settings/firms');
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

        $this->submitNew(['name' => '', 'registrationNumber' => '', 'vatId' => '123', 'street' => '', 'bankAccounts' => 'CZ00 nonsense', 'vatStatus' => 'nonsense']);

        self::assertResponseStatusCodeSame(422);
        foreach (['name', 'registrationNumber', 'vatId', 'street', 'bankAccounts', 'vatStatus'] as $field) {
            self::assertSelectorExists('#'.$field.'[aria-invalid="true"]');
        }
        self::assertSelectorTextContains('#bankAccounts', 'CZ00 nonsense');
        self::assertSame(0, $this->firmCount());
    }

    public function testAnInvalidRegistrationNumberIsAFormError(): void
    {
        $this->signIn();

        $this->submitNew(['registrationNumber' => '12345678']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#registrationNumber[aria-invalid="true"]');
    }

    public function testSubmittingTheSameFormTwiceCreatesOneFirm(): void
    {
        $this->signIn();
        $form = $this->newForm();

        $this->client->submit($form);
        self::assertResponseRedirects('/settings/firms');
        $this->client->submit($form);

        self::assertResponseRedirects('/settings/firms');
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

    public function testTheOwnerEditsAFirm(): void
    {
        $this->signIn();
        $firm = $this->firm('Example Firm', $this->tenant, '12345695');

        $crawler = $this->client->request('GET', '/settings/firms/'.$firm->id().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#lookup');
        self::assertSame('12345695', $crawler->filter('#registrationNumber')->attr('value'));

        $this->client->submit($crawler->filter('main form[data-controller]')->form(['name' => 'Renamed Firm', 'vatStatus' => 'not_registered', 'vatId' => '', 'bankAccounts' => self::IBAN]));

        self::assertResponseRedirects('/settings/firms');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The changes are saved.');
        $stored = $this->storedFirm();
        self::assertSame('Renamed Firm', $stored->name());
        self::assertSame([self::IBAN_STORED], $stored->bankAccounts());
        self::assertSame('12345695', $stored->registrationNumber()->value);
    }

    #[DataProvider('otherRoles')]
    public function testOtherRolesAreRefused(Role $role): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A'], role: $role);
        $this->browser->signIn();
        $id = $this->firm('Example Firm', $this->tenant, '12345695')->id();

        foreach ([['GET', '/settings/firms'], ['GET', '/settings/firms/new'], ['POST', '/settings/firms/new'], ['GET', '/settings/firms/'.$id.'/edit'], ['POST', '/settings/firms/'.$id.'/edit'], ['POST', '/settings/firms/'.$id.'/archive']] as [$method, $url]) {
            $this->client->request($method, $url);

            self::assertResponseStatusCodeSame(403, $method.' '.$url);
        }
    }

    /**
     * @return iterable<string, array{Role}>
     */
    public static function otherRoles(): iterable
    {
        yield 'member' => [Role::Member];
        yield 'subcontractor' => [Role::Subcontractor];
        yield 'client' => [Role::Client];
    }

    public function testAFirmOfAnotherTenantIsNotFound(): void
    {
        $this->signIn();
        $foreign = $this->firm('Foreign Firm a.s.', $this->foreign, '12345687');
        $own = $this->firm('Example Firm', $this->tenant, '12345695');
        $token = $this->actionToken($own);

        $this->client->request('GET', '/settings/firms/'.$foreign->id().'/edit');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/settings/firms/'.$foreign->id().'/edit', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/settings/firms/'.$foreign->id().'/archive', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testARegistrationNumberIsUniqueAmongTheActiveFirmsOfATenant(): void
    {
        $this->signIn();
        $this->firm('Example Firm', $this->tenant, '12345695');

        $this->submitNew(['name' => 'Second Firm', 'registrationNumber' => '1234 5695']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#registrationNumber[aria-invalid="true"]');
        self::assertSelectorTextContains('main', 'Another firm already has this registration number.');
        self::assertSame(1, $this->firmCount());
    }

    public function testAnotherTenantMayHaveTheSameRegistrationNumber(): void
    {
        $this->signIn();
        $this->firm('Foreign Firm a.s.', $this->foreign, '12345687');

        $this->submitNew();

        self::assertResponseRedirects('/settings/firms');
    }

    public function testAnArchivedFirmReleasesItsRegistrationNumber(): void
    {
        $this->signIn();
        $firm = $this->firm('Old Firm', $this->tenant, '12345687');
        $this->archive($firm);

        $this->submitNew(['name' => 'New Firm', 'registrationNumber' => '12345687']);

        self::assertResponseRedirects('/settings/firms');
        self::assertSame(2, $this->firmCount());
    }

    public function testAFirmCannotTakeTheNumberOfAnotherOne(): void
    {
        $this->signIn();
        $this->firm('Example Firm', $this->tenant, '12345695');
        $other = $this->firm('Other Firm', $this->tenant, '12345687');

        $crawler = $this->client->request('GET', '/settings/firms/'.$other->id().'/edit');
        $this->client->submit($crawler->filter('main form[data-controller]')->form(['registrationNumber' => '12345695']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#registrationNumber[aria-invalid="true"]');
    }

    public function testLosingTheRaceForANumberIsAFormError(): void
    {
        $this->signIn();
        $this->firm('Example Firm', $this->tenant, '12345687');
        $form = $this->newForm();
        RacingLegalEntities::$blind = true;

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#registrationNumber[aria-invalid="true"]');
        RacingLegalEntities::$blind = false;
        self::assertSame(1, $this->firmCount());
    }

    public function testArchivingMovesAFirmToTheArchivedView(): void
    {
        $this->signIn();
        $firm = $this->firm('Example Firm', $this->tenant, '12345695');
        $this->firm('Other Firm', $this->tenant, '12345687');

        $crawler = $this->client->request('GET', '/settings/firms/'.$firm->id().'/edit');
        $this->client->submit($crawler->filter('form[action$="/archive"]')->form());

        self::assertResponseRedirects('/settings/firms');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The firm is archived.');
        self::assertSelectorTextNotContains('main table', 'Example Firm');

        $this->client->request('GET', '/settings/firms?view=archived');
        self::assertSelectorTextContains('main table', 'Example Firm');
        self::assertSelectorTextNotContains('main table', 'Other Firm');
    }

    public function testTheArchiveButtonAsksForConfirmation(): void
    {
        $this->signIn();
        $firm = $this->firm('Example Firm', $this->tenant, '12345695');

        $crawler = $this->client->request('GET', '/settings/firms/'.$firm->id().'/edit');

        self::assertSame('Archive this firm? It cannot be restored.', $crawler->filter('form[action$="/archive"]')->attr('data-turbo-confirm'));
    }

    public function testTheLastActiveFirmCannotBeArchived(): void
    {
        $this->signIn();
        $firm = $this->firm('Example Firm', $this->tenant, '12345695');

        $this->client->request('POST', '/settings/firms/'.$firm->id().'/archive', ['_token' => $this->actionToken($firm)]);

        self::assertResponseRedirects('/settings/firms');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The last active firm cannot be archived.');
        self::assertSelectorTextNotContains('main', 'The firm is archived.');
        self::assertFalse($this->storedFirm()->isArchived());
    }

    public function testTheRefusalIsTranslated(): void
    {
        $this->signIn('cs');
        $firm = $this->firm('Example Firm', $this->tenant, '12345695');

        $this->client->request('POST', '/settings/firms/'.$firm->id().'/archive', ['_token' => $this->actionToken($firm)]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('main', 'Poslední aktivní firmu nelze archivovat.');
    }

    public function testAnArchivedFirmDoesNotKeepTheLastActiveOneArchivable(): void
    {
        $this->signIn();
        $this->archive($this->firm('Old Firm', $this->tenant, '12345687'));
        $firm = $this->firm('Example Firm', $this->tenant, '12345695');

        $this->client->request('POST', '/settings/firms/'.$firm->id().'/archive', ['_token' => $this->actionToken($firm)]);

        self::assertFalse($this->stored($firm)->isArchived());
    }

    public function testAFirmOfAnotherTenantDoesNotKeepTheLastActiveOneArchivable(): void
    {
        $this->signIn();
        $this->firm('Foreign Firm a.s.', $this->foreign, '12345687');
        $firm = $this->firm('Example Firm', $this->tenant, '12345695');

        $this->client->request('POST', '/settings/firms/'.$firm->id().'/archive', ['_token' => $this->actionToken($firm)]);

        self::assertFalse($this->stored($firm)->isArchived());
    }

    public function testArchivingStopsAtTheLastActiveFirm(): void
    {
        $this->signIn();
        $first = $this->firm('Example Firm', $this->tenant, '12345695');
        $second = $this->firm('Other Firm', $this->tenant, '12345687');
        $token = $this->actionToken($first);

        $this->client->request('POST', '/settings/firms/'.$first->id().'/archive', ['_token' => $token]);
        $this->client->request('POST', '/settings/firms/'.$second->id().'/archive', ['_token' => $token]);

        self::assertTrue($this->stored($first)->isArchived());
        self::assertFalse($this->stored($second)->isArchived());
    }

    public function testActiveFirmsAreLockedOnlyInsideATransaction(): void
    {
        $this->signIn();
        $this->archive($this->firm('Old Firm', $this->tenant, '12345687'));
        $firm = $this->firm('Example Firm', $this->tenant, '12345695');
        $firms = self::getContainer()->get(LegalEntities::class);
        $this->context->switchTo($this->tenant);

        self::assertSame([$firm->id()], $this->em()->wrapInTransaction(static fn (): array => array_map(static fn (LegalEntity $active): string => $active->id(), $firms->lockActive())));

        $this->expectException(TransactionRequiredException::class);
        $firms->lockActive();
    }

    public function testAForgedArchiveIsRefused(): void
    {
        $this->signIn();
        $firm = $this->firm('Example Firm', $this->tenant, '12345695');

        $this->client->request('POST', '/settings/firms/'.$firm->id().'/archive', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->storedFirm()->isArchived());
    }

    public function testAForgedSaveIsRefused(): void
    {
        $this->signIn();

        $this->client->request('POST', '/settings/firms/new', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnIdThatIsNotAUuidIsNotARoute(): void
    {
        $this->signIn();

        $this->client->request('GET', '/settings/firms/12/edit');

        self::assertResponseStatusCodeSame(404);
    }

    private function signIn(string $locale = 'en'): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A'], $locale);
        $this->foreign = $this->browser->fixtures->tenant('Foreign Tenant');
        $this->browser->signIn();
    }

    private function firm(string $name, TenantId $tenant, string $number): LegalEntity
    {
        $em = $this->em();
        $this->context->switchTo($tenant);
        $firm = new LegalEntity($name, new RegistrationNumber('CZ', $number), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '', []);
        $em->persist($firm);
        $em->flush();
        $this->context->reset();

        return $firm;
    }

    private function archive(LegalEntity $firm): void
    {
        $em = $this->em();
        $this->context->switchTo($firm->tenantId());
        $stored = $em->getRepository(LegalEntity::class)->find($firm->id()) ?? throw new \LogicException('The firm is missing.');
        $stored->archive(new \DateTimeImmutable('2026-10-31 12:00:00'));
        $em->flush();
        $this->context->reset();
    }

    private function newForm(): Form
    {
        $crawler = $this->client->request('GET', '/settings/firms/new');

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
        $this->client->request('POST', '/settings/firms/new', $values + $form->getPhpValues());
    }

    private function actionToken(LegalEntity $firm): string
    {
        $crawler = $this->client->request('GET', '/settings/firms/'.$firm->id().'/edit');

        return (string) $crawler->filter('form[action$="/archive"] input[name="_token"]')->attr('value');
    }

    private function storedFirm(): LegalEntity
    {
        $em = $this->em();
        $em->clear();

        $firms = $em->getRepository(LegalEntity::class)->findAll();
        self::assertCount(1, $firms);

        return $firms[0];
    }

    private function stored(LegalEntity $firm): LegalEntity
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(LegalEntity::class)->find($firm->id()) ?? throw new \LogicException('The firm is missing.');
    }

    private function firmCount(): int
    {
        return \count($this->em()->getConnection()->fetchFirstColumn('SELECT id FROM legal_entity'));
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
