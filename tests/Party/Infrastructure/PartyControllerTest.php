<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure;

use App\Party\Application\ArchiveParty;
use App\Party\Application\CreateParty;
use App\Party\Domain\Address;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Tests\Party\Infrastructure\Support\RejectingParties;
use App\Tests\Support\Registry\RegistryCalls;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PartyControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private TenantContext $context;
    private TenantId $tenant;
    private TenantId $foreign;

    protected function tearDown(): void
    {
        RejectingParties::$reject = false;
        RegistryCalls::reset();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), $this->context);
    }

    public function testAnOldNumericContactUrlIsNotFound(): void
    {
        $this->signIn();

        $this->client->request('GET', '/contacts/12');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnIdThatIsNotAUuidNeverReachesTheDatabase(): void
    {
        $this->signIn();
        $company = $this->party('Example Company s.r.o.', $this->tenant);

        // PostgreSQL refuses such a value for a uuid column, which must not become a server error.
        $this->client->request('GET', '/contacts?import=12&outcome=create');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Example Company s.r.o.');

        $this->client->request('POST', '/contacts/'.$company->id().'/link', ['_token' => $this->token('party_action'), 'person' => '12']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testListShowsOnlyTheContactsOfTheActiveTenant(): void
    {
        $this->signIn();
        $this->party('Example Company s.r.o.', $this->tenant);
        $this->party('Foreign Trading a.s.', $this->foreign);

        $this->client->request('GET', '/contacts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main table', 'Example Company s.r.o.');
        self::assertSelectorTextNotContains('body', 'Foreign Trading');
        self::assertSelectorCount(1, 'main tbody tr');
        self::assertSelectorTextContains('#sidebar', 'Address book');
    }

    public function testArchivedContactsAreHiddenUnlessTheArchivedViewIsOpen(): void
    {
        $this->signIn();
        $this->party('Active Company', $this->tenant);
        $archived = $this->party('Archived Company', $this->tenant);
        $this->archive($archived);

        $this->client->request('GET', '/contacts');
        self::assertSelectorTextNotContains('main table', 'Archived Company');
        self::assertSelectorTextContains('main table', 'Active Company');

        $this->client->request('GET', '/contacts?view=archived');
        self::assertSelectorTextContains('main table', 'Archived Company');
        self::assertSelectorTextNotContains('main table', 'Active Company');
    }

    public function testViewsFilterByRoleAndTheKpiStripCountsThem(): void
    {
        $this->signIn();
        $this->party('Customer Only', $this->tenant, roles: [PartyRole::Customer]);
        $this->party('Supplier Only', $this->tenant, roles: [PartyRole::Supplier]);
        $this->party('Both Roles', $this->tenant, roles: [PartyRole::Customer, PartyRole::Supplier]);
        $this->party('Unreliable Company', $this->tenant, vat: 'CZ12345687', refresh: true);

        $this->client->request('GET', '/contacts?view=suppliers');
        self::assertSelectorCount(2, 'main tbody tr');
        self::assertSelectorTextNotContains('main table', 'Customer Only');

        $crawler = $this->client->request('GET', '/contacts');
        $kpis = $crawler->filter('[data-kpi]')->each(static fn (Crawler $kpi): string => preg_replace('/\s+/', ' ', trim($kpi->text())) ?? '');
        self::assertCount(4, $kpis);
        self::assertStringContainsString('4', $kpis[0]);
        self::assertStringContainsString('3', $kpis[1]);
        self::assertStringContainsString('2', $kpis[2]);
        self::assertStringContainsString('1', $kpis[3]);
    }

    public function testSearchCoversNameRegistrationNumberVatIdAndEmail(): void
    {
        $this->signIn();
        $this->party('Alpha Trading', $this->tenant, number: '12345687', email: 'orders@example.com');
        $this->party('Beta Services', $this->tenant, vat: 'CZ12345695', email: 'office@example.org');

        $found = static fn (Crawler $crawler): int => $crawler->filter('main tbody tr')->count();

        self::assertSame(1, $found($this->client->request('GET', '/contacts?q=alpha')));
        self::assertSame(1, $found($this->client->request('GET', '/contacts?q=1234+5687')));
        self::assertSame(1, $found($this->client->request('GET', '/contacts?q=cz12345695')));
        self::assertSame(1, $found($this->client->request('GET', '/contacts?q=OFFICE@example')));
        self::assertSame(0, $found($this->client->request('GET', '/contacts?q=nothing-like-this')));
        self::assertSelectorExists('main h2');
        // Wildcards typed by the user are literal characters.
        self::assertSame(0, $found($this->client->request('GET', '/contacts?q=%25')));
    }

    public function testSortingAndPaginationWork(): void
    {
        $this->signIn();
        for ($i = 1; $i <= 27; ++$i) {
            $this->party(sprintf('Company %02d', $i), $this->tenant);
        }

        $crawler = $this->client->request('GET', '/contacts');
        self::assertSelectorCount(25, 'main tbody tr');
        self::assertSame('Company 01', trim($crawler->filter('main tbody tr')->first()->filter('td')->eq(1)->text()));
        self::assertSelectorExists('nav[aria-label="Pagination"]');

        $crawler = $this->client->request('GET', '/contacts?page=2');
        self::assertSelectorCount(2, 'main tbody tr');

        $crawler = $this->client->request('GET', '/contacts?sort=-name');
        self::assertSame('Company 27', trim($crawler->filter('main tbody tr')->first()->filter('td')->eq(1)->text()));
        self::assertSelectorExists('th[aria-sort="descending"]');
    }

    public function testEmptyTenantShowsTheEmptyStateWithTheAddAction(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/contacts');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('table');
        self::assertSelectorExists('main h2');
        self::assertSame('Add your first contact and it will show up here with your customers and suppliers.', trim($crawler->filter('main p')->first()->text()));
        self::assertSelectorExists('main a[href="/contacts/new"]');
    }

    public function testContactOfAnotherTenantIsNotFoundEverywhere(): void
    {
        $this->signIn();
        $foreign = $this->party('Foreign Trading a.s.', $this->foreign);
        $person = $this->party('Test Person', $this->tenant, kind: PartyKind::Person);
        $id = $foreign->id();
        $token = $this->token('party_action');

        $this->client->request('GET', '/contacts/'.$id);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/contacts/'.$id.'/edit');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/contacts/'.$id.'/edit');
        self::assertResponseStatusCodeSame(404);
        foreach (['archive', 'restore', 'link', 'registry-checks'] as $action) {
            $this->client->request('POST', '/contacts/'.$id.'/'.$action, ['_token' => $token, 'person' => $person->id()]);
            self::assertResponseStatusCodeSame(404, $action);
        }
        $this->client->request('POST', '/contacts/archive', ['_token' => $token, 'ids' => [$id, $person->id()]]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/contacts/export', ['_token' => $token, 'ids' => [$id]]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/contacts/99999');
        self::assertResponseStatusCodeSame(404);

        // Nothing was archived by the rejected bulk request.
        $this->client->request('GET', '/contacts');
        self::assertSelectorTextContains('main table', 'Test Person');
        self::assertSelectorExists('a[href="/contacts/new"]');

        // A foreign person cannot be linked either.
        $company = $this->party('Own Company', $this->tenant);
        $foreignPerson = $this->party('Foreign Person', $this->foreign, kind: PartyKind::Person);
        $this->client->request('POST', '/contacts/'.$company->id().'/link', ['_token' => $token, 'person' => $foreignPerson->id()]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCreatingAContactSavesItAndSchedulesTheRegistryChecksAfterTheResponse(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/contacts/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('main form')->form($this->values(['displayName' => 'Example Company s.r.o.', 'registrationNumber' => '1234 5687', 'vatId' => 'cz 12345687', 'email' => 'info@example.com']));
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(302);
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Example Company s.r.o.');
        self::assertSelectorTextContains('[data-party-summary]', 'Unreliable VAT payer');
        self::assertSelectorTextContains('main', 'CZ12345687');
        self::assertSelectorTextContains('main', 'The contact is saved.');
        // The checks ran after the response and were stored with their time.
        self::assertSelectorTextContains('[data-published-accounts]', '2000000011/0800');
        self::assertSelectorTextContains('[data-published-accounts]', 'CZ7308000000002000000007');
        self::assertSelectorExists('h1 ~ span, h1 + span');
    }

    public function testSubmittingTheSameFormTwiceCreatesOneContact(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/contacts/new');
        $form = $crawler->filter('main form')->form($this->values(['displayName' => 'Double Click s.r.o.']));
        $values = $form->getPhpValues();

        $this->client->request('POST', '/contacts/new', $values);
        $first = (string) $this->client->getResponse()->headers->get('Location');
        $this->client->request('POST', '/contacts/new', $values);
        $second = (string) $this->client->getResponse()->headers->get('Location');

        self::assertMatchesRegularExpression('#^/contacts/[0-9a-f-]{36}$#', $first);
        self::assertSame($first, $second);
        self::assertSame(1, $this->partyCount());
    }

    public function testPostWithoutAValidCsrfTokenIsRejected(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/contacts/new');
        $values = $crawler->filter('main form')->form($this->values(['displayName' => 'Forged s.r.o.']))->getPhpValues();
        $values['_token'] = 'forged';

        $this->client->request('POST', '/contacts/new', $values);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->partyCount());
    }

    public function testInvalidSubmissionShowsErrorsAndKeepsTheEnteredValues(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/contacts/new');
        $form = $crawler->filter('main form')->form($this->values(['displayName' => '', 'registrationNumber' => '12345670', 'vatId' => 'CZ12', 'city' => 'Entered City']));

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#displayName-error', 'Fill in this field.');
        self::assertSelectorTextContains('#registrationNumber-error', 'Invalid registration number');
        self::assertSelectorTextContains('#vatId-error', 'Invalid VAT ID');
        self::assertSame('Entered City', $this->client->getCrawler()->filter('#city')->attr('value'));
        self::assertSame(0, $this->partyCount());
    }

    public function testEveryInvalidFieldIsMarkedAndTheFirstTakesTheFocus(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/contacts/new');
        self::assertSame('New contact', $crawler->filter('title')->text());
        self::assertNotNull($crawler->filter('main form')->attr('novalidate'));
        $form = $crawler->filter('main form')->form($this->values(['displayName' => '', 'vatId' => 'CZ12', 'street' => '', 'city' => 'Entered City']));

        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Error: New contact', $crawler->filter('title')->text());
        self::assertSame(['displayName', 'vatId', 'street'], $crawler->filter('main form [aria-invalid="true"]')->each(static fn (Crawler $control): string => (string) $control->attr('id')));
        foreach (['displayName', 'street'] as $id) {
            self::assertSame($id.'-error', $crawler->filter('#'.$id)->attr('aria-describedby'));
            self::assertStringContainsString('border-badge-red-text', (string) $crawler->filter('#'.$id)->attr('class'));
            self::assertSelectorTextContains('#'.$id.'-error', 'Fill in this field.');
        }
        self::assertSame('vatId-help vatId-error', $crawler->filter('#vatId')->attr('aria-describedby'));
        // Only the first invalid field takes the focus, so it works without JavaScript too.
        self::assertSame(['displayName'], $crawler->filter('[autofocus]')->each(static fn (Crawler $control): string => (string) $control->attr('id')));
        self::assertSame('CZ12', $crawler->filter('#vatId')->attr('value'));
        self::assertSame('Entered City', $crawler->filter('#city')->attr('value'));
        // The save button is never held back, and the action bar carries no error text.
        self::assertNull($crawler->selectButton('Save')->attr('disabled'));
        self::assertSelectorNotExists('[data-action-bar-status]');
    }

    public function testEditingKeepsTheInputAndMarksTheInvalidField(): void
    {
        $this->signIn();
        $party = $this->party('Example Company', $this->tenant);
        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        $form = $crawler->filter('main form')->form(['displayName' => 'Example Renamed', 'postalCode' => '', 'email' => 'not-an-email']);

        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Error: Edit', $crawler->filter('title')->text());
        self::assertSame(['email', 'postalCode'], $crawler->filter('main form [aria-invalid="true"]')->each(static fn (Crawler $control): string => (string) $control->attr('id')));
        self::assertSame(['email'], $crawler->filter('[autofocus]')->each(static fn (Crawler $control): string => (string) $control->attr('id')));
        self::assertSame('Example Renamed', $crawler->filter('#displayName')->attr('value'));
    }

    public function testADomainErrorInsideTheWriteIsAFormErrorAndThePageStillQueries(): void
    {
        $this->signIn();
        $this->party('Existing Company', $this->tenant);
        $crawler = $this->client->request('GET', '/contacts/new');
        $form = $crawler->filter('main form')->form($this->values(['displayName' => 'Rejected s.r.o.']));

        RejectingParties::$reject = true;
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'The contact could not be saved.');
        // The page was rendered after the failed write: sidebar count and form both needed working queries.
        self::assertSelectorTextContains('#sidebar [data-nav-count]', '1');
        self::assertSame(1, $this->partyCount());

        $this->client->request('GET', '/contacts');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'main tbody tr');
    }

    public function testDuplicateRegistrationNumberWarnsWithALinkAndStillAllowsSaving(): void
    {
        $this->signIn();
        $existing = $this->party('Existing Company', $this->tenant, number: '12345687');
        $crawler = $this->client->request('GET', '/contacts/new');
        $form = $crawler->filter('main form')->form($this->values(['displayName' => 'Second Company', 'registrationNumber' => '12345687']));

        $crawler = $this->client->submit($form);

        // 422, not 200: Turbo only renders a non-redirect form answer when it is a 4xx.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-duplicate-warning]');
        self::assertSelectorExists('[data-duplicate-warning] a[href="/contacts/'.$existing->id().'"]');
        self::assertSame(1, $this->partyCount());

        $this->client->submit($crawler->filter('main form')->form(), [], []);
        // Without the confirmation the warning appears again.
        self::assertSelectorExists('[data-duplicate-warning]');

        $values = $crawler->filter('main form')->form()->getPhpValues();
        $values['confirm_duplicate'] = '1';
        $this->client->request('POST', '/contacts/new', $values);

        self::assertResponseStatusCodeSame(302);
        self::assertSame(2, $this->partyCount());
    }

    public function testEditChangesTheContactAndDoesNotWarnAboutItself(): void
    {
        $this->signIn();
        $party = $this->party('Old Name', $this->tenant, number: '12345687', email: 'old@example.com');

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSame('Old Name', $crawler->filter('#displayName')->attr('value'));
        // Save and Cancel live in the docked bar at the end of the form, Cancel first and Save on the right.
        $bar = $crawler->filter('main form [data-action-bar]');
        self::assertSame(['Cancel', 'Save'], $bar->filter('a, button')->each(static fn (Crawler $action): string => trim($action->text())));
        self::assertSame('/contacts/'.$party->id(), $bar->filter('a')->attr('href'));
        self::assertCount(1, $bar->filter('button[type="submit"]'));
        $form = $crawler->filter('main form')->form(['displayName' => 'New Name', 'email' => 'new@example.com', 'city' => 'Moved City']);
        $this->client->submit($form);

        self::assertResponseRedirects('/contacts/'.$party->id());
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'New Name');
        self::assertSelectorTextContains('[data-party-facts], main', 'new@example.com');
        self::assertSelectorTextContains('main', 'Moved City');
    }

    public function testMobileAndWebsiteAreEditedAndShownOnlyWhenKnown(): void
    {
        $this->signIn();
        $party = $this->party('Example Company', $this->tenant, number: '12345687');

        $crawler = $this->client->request('GET', '/contacts/'.$party->id());
        self::assertCount(4, $crawler->filter('main dl dt')->reduce(static fn ($node): bool => \in_array($node->text(), ['Registration number', 'VAT ID', 'E-mail', 'Phone', 'Mobile', 'Website'], true)));

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        $form = $crawler->filter('main form')->form(['mobile' => ' +420 987 654 321 ', 'website' => 'https://www.example.com']);
        $this->client->submit($form);

        self::assertResponseRedirects('/contacts/'.$party->id());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', '+420 987 654 321');
        self::assertSelectorTextContains('main', 'https://www.example.com');
        self::assertSame(['Mobile', 'Website'], $crawler->filter('main dl dt')->reduce(static fn ($node): bool => \in_array($node->text(), ['Mobile', 'Website'], true))->each(static fn ($node): string => $node->text()));
        $link = $crawler->filter('main dl a[href="https://www.example.com"]');
        self::assertCount(1, $link);
        self::assertSame('noopener noreferrer', $link->attr('rel'));

        // A bare domain links with https in front; anything else stays text.
        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        $this->client->submit($crawler->filter('main form')->form(['website' => 'example.com']));
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('main dl a[href="https://example.com"]'));
        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        $this->client->submit($crawler->filter('main form')->form(['website' => 'javascript:alert(1)']));
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('main dl a'));
        self::assertSelectorTextContains('main dl', 'javascript:alert(1)');

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        self::assertSame('+420 987 654 321', $crawler->filter('#mobile')->attr('value'));
        self::assertSame('javascript:alert(1)', $crawler->filter('#website')->attr('value'));
    }

    public function testTooLongMobileAndWebsiteAreFormErrors(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/contacts/new');
        $this->client->submit($crawler->filter('main form')->form($this->values(['mobile' => str_repeat('1', 65), 'website' => 'https://'.str_repeat('a', 250)])));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#mobile-error', 'too long');
        self::assertSelectorTextContains('#website-error', 'too long');
    }

    public function testArchiveAndRestoreAreCsrfProtectedPosts(): void
    {
        $this->signIn();
        $party = $this->party('Example Company', $this->tenant);
        $this->client->request('GET', '/contacts/'.$party->id().'/archive');
        self::assertResponseStatusCodeSame(405);
        $this->client->request('POST', '/contacts/'.$party->id().'/archive', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/contacts/'.$party->id().'/archive', ['_token' => $this->token('party_action')]);
        self::assertResponseRedirects('/contacts/'.$party->id());
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Example Company');
        self::assertSelectorTextContains('[data-party-summary]', 'Archived');

        $this->client->request('POST', '/contacts/'.$party->id().'/restore', ['_token' => $this->token('party_action')]);
        $this->client->followRedirect();
        self::assertSelectorTextNotContains('[data-party-summary]', 'Archived');
    }

    public function testBulkArchiveArchivesTheSelectedContacts(): void
    {
        $this->signIn();
        $a = $this->party('First Company', $this->tenant);
        $this->party('Second Company', $this->tenant);
        $c = $this->party('Third Company', $this->tenant);

        $this->client->request('POST', '/contacts/archive', ['_token' => $this->token('party_action'), 'ids' => [$a->id(), $c->id()]]);

        self::assertResponseRedirects('/contacts');
        $this->client->followRedirect();
        self::assertSelectorCount(1, 'main tbody tr');
        self::assertSelectorTextContains('main table', 'Second Company');
    }

    public function testCsvExportContainsOnlyTheSelectedContactsOfTheActiveTenant(): void
    {
        $this->signIn();
        $mine = $this->party('Example Company s.r.o.', $this->tenant, number: '12345687', vat: 'CZ12345687', email: 'info@example.com');
        $this->party('Not Selected', $this->tenant);
        $foreign = $this->party('Foreign Trading a.s.', $this->foreign);
        $formula = $this->party('=HYPERLINK("http://example.com")', $this->tenant);

        $this->client->request('POST', '/contacts/export', ['_token' => $this->token('party_action'), 'ids' => [$mine->id(), $foreign->id(), $formula->id(), 'abc', '']]);

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename=contacts-\d{8}\.csv$/', (string) $response->headers->get('Content-Disposition'));
        $csv = $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF\"Číslo kontaktu ve Foresi\";Název;Druh;", $csv);
        self::assertStringContainsString(';"Example Company s.r.o.";Firma;12345687;', $csv);
        self::assertStringContainsString(';CZ12345687;info@example.com;', $csv);
        self::assertStringNotContainsString('Foreign Trading', $csv);
        self::assertStringNotContainsString('Not Selected', $csv);
        self::assertStringContainsString("'=HYPERLINK", $csv);
        self::assertCount(3, array_filter(explode("\n", $csv), static fn (string $line): bool => '' !== $line), 'header and two selected contacts');
    }

    public function testXlsxExportIsAWorkbookWithTheSameContacts(): void
    {
        $this->signIn();
        $mine = $this->party('Example Company s.r.o.', $this->tenant, number: '12345687');

        $this->client->request('POST', '/contacts/export', ['_token' => $this->token('party_action'), 'ids' => [$mine->id()], 'format' => 'xlsx']);

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename=contacts-\d{8}\.xlsx$/', (string) $response->headers->get('Content-Disposition'));
        $content = $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith('PK', $content);
        $path = tempnam(sys_get_temp_dir(), 'foresi-xlsx');
        file_put_contents($path, $content);
        try {
            $rows = [...new \App\Import\Infrastructure\Source\OpenSpoutSourceReader()->rows($path, new \App\Import\Application\Source\SourceSettings(\App\Import\Application\Source\SourceFormat::Xlsx, headerRow: 1))];
        } finally {
            unlink($path);
        }
        self::assertCount(1, $rows);
        self::assertContains('Example Company s.r.o.', $rows[0]);
    }

    public function testLookupReturnsTheMappedRegistryRecord(): void
    {
        $this->signIn();
        $existing = $this->party('Own Copy', $this->tenant, number: '12345687');
        $this->party('Foreign Copy', $this->foreign, number: '12345687');

        $this->client->request('GET', '/contacts/lookup?q=1234 5687');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame([[
            'registrationCountry' => 'CZ',
            'registrationNumber' => '12345687',
            'name' => 'Example Company s.r.o.',
            'vatId' => 'CZ12345687',
            'street' => 'Příkladná 1444/2a',
            'city' => 'Ostrava',
            'postalCode' => '702 00',
            'countryCode' => 'CZ',
            'existing' => ['name' => 'Own Copy', 'url' => '/contacts/'.$existing->id()],
        ]], $data['results']);
    }

    public function testLookupByNameNeedsThreeLettersAndUnknownNumbersFindNothing(): void
    {
        $this->signIn();

        $this->client->request('GET', '/contacts/lookup?q=exa');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['results']);
        self::assertCount(1, $data['results']);
        self::assertIsArray($data['results'][0]);
        self::assertNull($data['results'][0]['existing']);

        foreach (['ex', '', '12345695', '12345670', 'zzzz'] as $query) {
            $this->client->request('GET', '/contacts/lookup?q='.urlencode($query));
            self::assertResponseIsSuccessful();
            self::assertSame('{"results":[]}', $this->client->getResponse()->getContent(), $query);
        }
    }

    public function testDetailShowsTheUnreliablePayerBadgeTheCheckTimeAndThePublishedAccounts(): void
    {
        $this->signIn('cs');
        $party = $this->party('Unreliable Company', $this->tenant, vat: 'CZ12345687', refresh: true);

        RegistryCalls::reset();

        $this->client->request('GET', '/contacts/'.$party->id());

        self::assertResponseIsSuccessful();
        self::assertSame(0, RegistryCalls::$count, 'viewing a contact never asks a register');
        self::assertSelectorTextContains('h1 + span', 'Nespolehlivý plátce DPH');
        self::assertSelectorTextContains('[data-party-summary]', 'zkontrolováno');
        self::assertSelectorExists('[data-party-summary] time[datetime]');
        self::assertSelectorTextContains('[data-published-accounts]', '19-2000000007/0800');
    }

    public function testNotRegisteredPayersAreLabelledAsNotVatPayers(): void
    {
        $this->signIn('cs');
        $party = $this->party('Small Trader', $this->tenant, vat: 'CZ99999994', refresh: true);

        $this->client->request('GET', '/contacts/'.$party->id());

        self::assertSelectorTextContains('[data-party-summary]', 'Není plátce DPH');
        self::assertSelectorNotExists('[data-published-accounts]');
    }

    public function testLookupIsRateLimitedPerUserWithAJsonAnswer(): void
    {
        $this->signIn();

        for ($i = 0; $i < 30; ++$i) {
            $this->client->request('GET', '/contacts/lookup?q=exa');
            self::assertResponseIsSuccessful();
        }
        $this->client->request('GET', '/contacts/lookup?q=exa');

        self::assertResponseStatusCodeSame(429);
        self::assertSame('{"error":"rate_limited"}', $this->client->getResponse()->getContent());
        self::assertGreaterThanOrEqual(1, (int) $this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testRegistrationNumbersAreLookedUpOnlyAtExactlyEightDigits(): void
    {
        $this->signIn();
        RegistryCalls::reset();

        foreach (['1234568', '123456870', '1234 568'] as $query) {
            $this->client->request('GET', '/contacts/lookup?q='.urlencode($query));
            self::assertSame('{"results":[]}', $this->client->getResponse()->getContent(), $query);
        }
        self::assertSame(0, RegistryCalls::$count);

        $this->client->request('GET', '/contacts/lookup?q='.urlencode('1234 5687'));
        self::assertSame(1, RegistryCalls::$count);
        self::assertStringContainsString('Example Company s.r.o.', (string) $this->client->getResponse()->getContent());
    }

    public function testLongSearchTextIsCutToOneHundredCharacters(): void
    {
        $this->signIn();
        $this->party('Example Company', $this->tenant);

        $crawler = $this->client->request('GET', '/contacts?q='.str_repeat('a', 300));

        self::assertResponseIsSuccessful();
        self::assertSame(100, \strlen((string) $crawler->filter('input[type="search"]')->attr('value')));
    }

    public function testManualRefreshStoresThePublishedAccountsSoViewingNeedsNoRegister(): void
    {
        $this->signIn();
        $party = $this->party('Unreliable Company', $this->tenant, vat: 'CZ12345687');
        $this->client->request('POST', '/contacts/'.$party->id().'/registry-checks', ['_token' => $this->token('party_action')]);
        RegistryCalls::reset();

        $this->client->request('GET', '/contacts/'.$party->id());

        self::assertSame(0, RegistryCalls::$count);
        self::assertSelectorTextContains('[data-published-accounts]', 'CZ7308000000002000000007');
    }

    public function testRegistryChecksCanBeRefreshedByHand(): void
    {
        $this->signIn();
        $party = $this->party('Example Company', $this->tenant, vat: 'CZ12345695');

        $this->client->request('GET', '/contacts/'.$party->id());
        self::assertSelectorTextContains('main', 'Not checked yet');
        $this->client->request('POST', '/contacts/'.$party->id().'/registry-checks', ['_token' => $this->token('party_action')]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('[data-party-summary]', 'Valid in VIES');
        self::assertSelectorTextContains('[data-party-summary]', 'Reliable VAT payer');
    }

    public function testRefreshingChecksIsLimitedPerUser(): void
    {
        $this->signIn();
        $party = $this->party('Example Company', $this->tenant, vat: 'CZ12345695');

        for ($i = 0; $i < 10; ++$i) {
            $this->client->request('POST', '/contacts/'.$party->id().'/registry-checks', ['_token' => $this->token('party_action')]);
            $this->client->followRedirect();
            self::assertSelectorNotExists('[role="alert"]');
        }
        RegistryCalls::reset();
        $this->client->request('POST', '/contacts/'.$party->id().'/registry-checks', ['_token' => $this->token('party_action')]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('[role="alert"]', 'Too many refreshes');
        self::assertSame(0, RegistryCalls::$count);
    }

    public function testContactPersonsCanBeLinkedToACompany(): void
    {
        $this->signIn();
        $company = $this->party('Example Company', $this->tenant);
        $person = $this->party('Test Person', $this->tenant, kind: PartyKind::Person);

        $crawler = $this->client->request('GET', '/contacts/'.$company->id().'?tab=contacts');
        self::assertSelectorTextContains('main', 'There is no contact person yet.');
        $this->client->submit($crawler->filter('form[action$="/link"]')->form(['person' => $person->id(), 'position' => 'Accountant', 'primary' => '1']));
        $this->client->followRedirect();

        self::assertSelectorTextContains('main table', 'Test Person');
        self::assertSelectorTextContains('main table', 'Accountant');
        self::assertSelectorTextContains('main', 'The contact person is added.');

        // The person sees the company, and the pair cannot be linked twice.
        $this->client->request('GET', '/contacts/'.$person->id().'?tab=contacts');
        self::assertSelectorTextContains('main table', 'Example Company');
        $this->client->request('POST', '/contacts/'.$company->id().'/link', ['_token' => $this->token('party_action'), 'person' => $person->id()]);
        self::assertResponseStatusCodeSame(422);
        // No other person is left to offer, so the message stands on its own.
        self::assertSelectorTextContains('[data-link-error]', 'Choose a person who is not listed for the company yet.');
    }

    public function testARefusedContactPersonIsShownAgainWithEveryProblemAtItsField(): void
    {
        $this->signIn();
        $company = $this->party('Example Company', $this->tenant);
        $person = $this->party('Test Person', $this->tenant, kind: PartyKind::Person);
        $crawler = $this->client->request('GET', '/contacts/'.$company->id().'?tab=contacts');
        self::assertNotNull($crawler->filter('form[action$="/link"]')->attr('novalidate'));
        $form = $crawler->filter('form[action$="/link"]')->form();
        // The page's maxlength stops typing, not a request: the server checks the length too.
        $form->disableValidation()->setValues(['person' => '', 'position' => str_repeat('x', 101), 'primary' => '1']);

        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Error: Example Company', $crawler->filter('title')->text());
        self::assertSame(['link-person', 'link-position'], $crawler->filter('[aria-invalid="true"]')->each(static fn (Crawler $control): string => (string) $control->attr('id')));
        self::assertSame(['link-person'], $crawler->filter('[autofocus]')->each(static fn (Crawler $control): string => (string) $control->attr('id')));
        self::assertSame('link-person-error', $crawler->filter('#link-person')->attr('aria-describedby'));
        self::assertSelectorTextContains('#link-position-error', 'too long');
        // What was entered is kept and the button is not held back.
        self::assertSame(str_repeat('x', 101), $crawler->filter('#link-position')->attr('value'));
        self::assertNotNull($crawler->filter('#link-primary')->attr('checked'));
        self::assertNull($crawler->selectButton('Add person')->attr('disabled'));
        self::assertSelectorTextContains('main', 'There is no contact person yet.');
        self::assertSame($person->id(), $crawler->filter('#link-person option')->attr('value'));
    }

    public function testAddressAndBankAccountTabsRender(): void
    {
        $this->signIn();
        $party = $this->party('Example Company', $this->tenant);

        $this->client->request('GET', '/contacts/'.$party->id().'?tab=addresses');
        self::assertSelectorTextContains('main table', 'Billing');
        self::assertSelectorTextContains('main table', 'Example Street 1');

        $this->client->request('GET', '/contacts/'.$party->id().'?tab=bank-accounts');
        self::assertSelectorTextContains('main', 'There is no bank account yet.');
    }

    public function testHistoryListsTheAuditEntriesOfThisContactOnly(): void
    {
        $this->signIn();
        $mine = $this->party('Example Company', $this->tenant, email: 'info@example.com');
        $this->party('Second Company', $this->tenant);
        $crawler = $this->client->request('GET', '/contacts/'.$mine->id().'/edit');
        $this->client->submit($crawler->filter('main form')->form(['displayName' => 'Renamed Company']));

        $crawler = $this->client->request('GET', '/contacts/'.$mine->id().'?tab=history');

        self::assertResponseIsSuccessful();
        $entries = $crawler->filter('[data-party-history] li')->each(static fn (Crawler $li): string => trim($li->text()));
        // Created party, billing address, e-mail, then the rename of the party.
        self::assertCount(4, $entries);
        self::assertStringContainsString('Changed: contact (Name)', implode("\n", $entries));
        self::assertStringContainsString('Created: address', implode("\n", $entries));
        self::assertStringContainsString('Created: e-mail', implode("\n", $entries));

        $second = $this->client->request('GET', '/contacts/'.$this->idOf('Second Company').'?tab=history');
        self::assertCount(2, $second->filter('[data-party-history] li'));
    }

    public function testTheHistoryOfAnEmailChangeNamesOnlyTheAddress(): void
    {
        $this->signIn();
        $party = $this->party('Example Company', $this->tenant, email: 'info@example.com');
        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'/edit');
        $this->client->submit($crawler->filter('main form')->form(['email' => 'Changed@example.com']));

        $crawler = $this->client->request('GET', '/contacts/'.$party->id().'?tab=history');

        $entries = implode("\n", $crawler->filter('[data-party-history] li')->each(static fn (Crawler $li): string => trim($li->text())));
        self::assertStringContainsString('Changed: e-mail (Address)', $entries);
        self::assertStringNotContainsString('addressKey', $entries);
        self::assertStringNotContainsString('address_key', $entries);
    }

    public function testListNeedsLogin(): void
    {
        $this->client->request('GET', '/contacts');

        self::assertResponseRedirects('http://localhost/login');
    }

    private function signIn(string $locale = 'en'): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A'], $locale);
        $this->foreign = $this->browser->fixtures->tenant('Foreign Tenant');
        $this->browser->signIn();
    }

    /**
     * @param list<PartyRole> $roles
     */
    private function party(string $name, TenantId $tenant, ?PartyKind $kind = null, array $roles = [PartyRole::Customer], ?string $number = null, ?string $vat = null, string $email = '', bool $refresh = false): Party
    {
        $this->context->switchTo($tenant);
        $party = self::getContainer()->get(CreateParty::class)->create(
            $kind ?? (null === $number && null === $vat ? PartyKind::SoleTrader : PartyKind::Company),
            $name,
            null === $number ? null : new RegistrationNumber('CZ', $number),
            null === $vat ? null : VatId::fromString($vat),
            $email,
            '',
            $roles,
            new Address('Example Street 1', 'Example City', '100 00', 'CZ'),
        );
        if ($refresh) {
            $checks = self::getContainer()->get(\App\Party\Application\RefreshRegistryChecks::class);
            $checks->refresh($party);
            self::getContainer()->get(EntityManagerInterface::class)->flush();
        }
        $this->context->reset();

        return $party;
    }

    private function archive(Party $party): void
    {
        $this->context->switchTo($this->tenant);
        $party = self::getContainer()->get(EntityManagerInterface::class)->find(Party::class, $party->id()) ?? throw new \LogicException();
        self::getContainer()->get(ArchiveParty::class)->archive([$party]);
        $this->context->reset();
    }

    private function token(string $id): string
    {
        self::assertSame('party_action', $id);
        // The export form of the list carries the token of the page actions (the list needs a contact for that).
        $crawler = $this->client->request('GET', '/contacts');

        return (string) $crawler->filter('form[action="/contacts/export"] input[name="_token"]')->attr('value');
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function values(array $overrides = []): array
    {
        return $overrides + ['kind' => 'sole_trader', 'displayName' => 'Example Company s.r.o.', 'registrationCountry' => 'CZ', 'registrationNumber' => '', 'vatId' => '', 'email' => '', 'phone' => '', 'street' => 'Example Street 1', 'city' => 'Example City', 'postalCode' => '100 00', 'countryCode' => 'CZ', 'privateNote' => ''];
    }

    private function partyCount(): int
    {
        $this->context->switchTo($this->tenant);
        $count = (int) self::getContainer()->get(EntityManagerInterface::class)->createQuery('SELECT COUNT(p.id) FROM '.Party::class.' p')->getSingleScalarResult();
        $this->context->reset();

        return $count;
    }

    private function idOf(string $name): string
    {
        $id = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT id FROM party WHERE display_name = ?', [$name]);
        self::assertIsString($id);

        return $id;
    }
}
