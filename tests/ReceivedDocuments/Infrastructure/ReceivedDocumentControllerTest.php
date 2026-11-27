<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ReceivedDocuments\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\ReceivedDocuments\Application\ReceivedDocumentWarning;
use Foresi\ReceivedDocuments\Domain\ReceivedDocument;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentLine;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentRefused;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentState;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\ExchangeRate\Support\FakeRateSource;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Yaml\Yaml;

/**
 * The screens of received documents: the list with its tabs and filters, the
 * upload, recording a draft and a new document, editing, the trash and a
 * restore, deleting a draft, and a guest kept out. Every write goes through
 * the HTTP layer and is read back from the database.
 */
final class ReceivedDocumentControllerTest extends WebTestCase
{
    private const array DIALOG = ['HTTP_X_FORESI_DIALOG' => '1', 'HTTP_ACCEPT' => 'application/json, text/html'];

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private Party $supplier;
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-10 09:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $rates = new FakeRateSource();
        $rates->publish('2026-10-28', '24.310', '15.120');
        self::getContainer()->set(RateSource::class, $rates);
        $this->browser = new SignedInBrowser($this->client, $this->em());
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheListOpensOnAllAndTheSidebarCountsTheDrafts(): void
    {
        $this->signedIn();
        $this->upload(['first.pdf' => '%PDF-1.4 first', 'second.pdf' => '%PDF-1.4 second']);

        $crawler = $this->page('/received-documents');

        self::assertSame('page', $crawler->filter('main nav a[href="/received-documents"]')->attr('aria-current'));
        self::assertSame(['All2', 'To complete2', 'To review0', 'Recorded0', 'Trash0'], $this->tabs($crawler));
        self::assertCount(2, $crawler->filter('main table tbody tr'));
        self::assertStringContainsString('Draft of', $crawler->filter('main table tbody')->text());
        $sidebar = $crawler->filter('#sidebar [data-nav-section] a[href="/received-documents"]');
        self::assertStringContainsString('Costs', $sidebar->text());
        self::assertStringContainsString('2', $sidebar->text());
        // Every action sits in the page header.
        self::assertCount(1, $crawler->filter('[data-page-actions] a[href="/received-documents/upload"][data-dialog]'));
        self::assertCount(1, $crawler->filter('[data-page-actions] a[href="/received-documents/new"][data-dialog]'));
    }

    public function testTheTabsAreTheShippedViewsCountedWithinTheSearchAndTheOldTabsStillOpen(): void
    {
        // Saved views, D12: the tabs it had, with the same counts (D9), counted within the search; the month is a condition of the working filter, which counts no tab.
        $this->signedIn();
        $recorded = $this->recorded();
        $this->draft('%PDF-1.4 a draft');
        $trashed = $this->draft('%PDF-1.4 a trashed draft');
        $this->client->request('POST', '/received-documents/'.$trashed.'/trash', ['_token' => $this->token('/received-documents/'.$trashed), 'reason' => 'Twice'], server: self::DIALOG);

        $crawler = $this->page('/received-documents');
        self::assertSame(['All2', 'To complete1', 'To review0', 'Recorded1', 'Trash1'], $this->tabs($crawler));
        self::assertSame('/received-documents?view=recorded', $crawler->filter('nav[aria-label="Received document views"] a')->eq(3)->attr('href'));
        self::assertCount(1, $crawler->filter('[data-filter-sheet-form]'));

        foreach (['/received-documents?view=drafts', '/received-documents?tab=drafts'] as $drafts) {
            $crawler = $this->page($drafts);
            self::assertCount(1, $crawler->filter('main table tbody tr'), $drafts);
            self::assertSame('page', $crawler->filter('nav[aria-label="Received document views"] a')->eq(1)->attr('aria-current'), $drafts);
        }
        self::assertStringContainsString('Twice', $this->page('/received-documents?tab=trash')->filter('main table tbody')->text());

        $crawler = $this->page('/received-documents?view=recorded&q=fv-2026');
        self::assertSame(['All1', 'To complete0', 'To review0', 'Recorded1', 'Trash0'], $this->tabs($crawler));
        self::assertStringContainsString('R20260001', $crawler->filter('main table tbody')->text());
        // An old ?month= link is the period quick filter now: a change to All nobody saved, the tabs counting their own views.
        $crawler = $this->page('/received-documents?month=2026-10');
        self::assertSame(['All, unsaved changes2', 'To complete1', 'To review0', 'Recorded1', 'Trash1'], $this->tabs($crawler));
        self::assertCount(1, $crawler->filter('main table tbody tr'), 'A draft names no month yet.');
        self::assertCount(1, $crawler->filter('main table tbody tr a[href="/received-documents/'.$recorded.'"]'));
    }

    public function testTheMonthChipOffersThePeriodsRelativeToToday(): void
    {
        $this->signedIn();
        $this->recorded();

        $crawler = $this->page('/received-documents?month=last_month');

        self::assertCount(1, $crawler->filter('main table tbody tr'), 'Today is in November; the document is of October.');
        // The period quick filter offers the periods, then the months with a document.
        $options = $crawler->filter('[data-filter-chips] [data-quick-filter="period"] [role="menuitemradio"]');
        self::assertSame(['this month', 'last month', 'this quarter', 'last quarter', 'this year', 'last year', 'October 2026'], $options->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertSame(['last month'], $options->filter('[aria-checked="true"]')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertSame('Month: last month', trim($crawler->filter('[data-filter-chips] [data-quick-filter="period"][data-set] summary')->first()->text()));
        self::assertCount(0, $this->page('/received-documents?month=this_month')->filter('main table tbody tr'));
        self::assertCount(1, $this->page('/received-documents?month=this_quarter')->filter('main table tbody tr'));
        // The month and the supplier are the view's quick filters: an instance holds one firm (ADR 0039), and an old ?firm= narrows nothing.
        $crawler = $this->page('/received-documents?firm=0190a8a8-0000-7000-8000-000000000000');
        self::assertSame(['period', 'supplier'], $crawler->filter('[data-filter-chips] [data-quick-filter]')->each(static fn (Crawler $chip): string => (string) $chip->attr('data-quick-filter')));
        self::assertCount(0, $crawler->filter('[data-filter-chips] [data-quick-filter][data-set], [data-filter-chip]'));
        self::assertCount(1, $crawler->filter('main table tbody tr'));
    }

    public function testTheOldSheetParametersAreConditionsOfTheBuilder(): void
    {
        $this->signedIn();
        $this->recorded();

        $crawler = $this->page('/received-documents?view=recorded&supplier='.$this->supplier->id().'&kind=tax_invoice&treatment=domestic');
        self::assertCount(1, $crawler->filter('main table tbody tr'));
        self::assertSame('Recorded, unsaved changes1', $this->tabs($crawler)[3]);
        // No fixed select sits in the filter sheet above its conditions (spec D11).
        self::assertCount(0, $crawler->filter('select[name="supplier"], select[name="kind"], select[name="treatment"]'));
        self::assertStringContainsString('Supplier Example s.r.o.', $crawler->filter('main')->text());

        self::assertCount(0, $this->page('/received-documents?view=recorded&kind=credit_note')->filter('main table tbody tr'));
        // A value the field does not take narrows nothing, as the sheet ignored it.
        self::assertCount(1, $this->page('/received-documents?view=recorded&kind=invoice&treatment=')->filter('main table tbody tr'));
    }

    public function testTheBuilderNarrowsTheViewAndItsChangesAreSavedAsANewView(): void
    {
        $this->signedIn();
        $this->recorded();
        $this->draft('%PDF-1.4 a draft');

        $this->client->request('GET', '/received-documents?'.http_build_query(['view' => 'all', 'kind' => 'other', 'page' => '2', 'conditions' => '1', 'c' => [
            ['field' => 'kind', 'op' => 'is', 'values' => ['tax_invoice']],
            ['field' => 'supplier', 'op' => 'is', 'value' => $this->supplier->id()],
        ]]));
        self::assertResponseStatusCodeSame(303);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('kind=', $location);
        self::assertStringNotContainsString('page=', $location);
        self::assertStringNotContainsString('view=', $location, 'All is the bare list.');
        $crawler = $this->client->followRedirect();

        self::assertCount(1, $crawler->filter('main tbody tr'));
        self::assertSame('All, unsaved changes2', $this->tabs($crawler)[0]);
        self::assertStringContainsString('Supplier Example s.r.o.', $crawler->filter('main')->text());
        $this->client->submit($crawler->filter('[data-view-save-as-form]')->form(['name' => 'Supplier invoices']));
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('main tbody tr'));
        self::assertContains('Supplier invoices', $this->tabs($crawler));
    }

    public function testTheColumnsSheetHidesAColumn(): void
    {
        $this->signedIn();
        $this->recorded();

        $crawler = $this->page('/received-documents?view=recorded&cols=number,total');

        self::assertSame(['Our number', 'Total'], $crawler->filter('main table thead th')->each(static fn (Crawler $th): string => trim($th->text())));
    }

    public function testUploadingManyFilesMakesADraftOfEachAndTellsARefusedOne(): void
    {
        $this->signedIn();

        $this->upload(['first.pdf' => '%PDF-1.4 first', 'empty.pdf' => '', 'second.pdf' => '%PDF-1.4 second']);

        self::assertResponseRedirects('/received-documents', 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('2 documents uploaded', $crawler->filter('main')->text());
        self::assertStringContainsString('empty.pdf', $crawler->filter('main [role="alert"]')->text());
        self::assertSame(2, $this->documentsIn(ReceivedDocumentState::Draft));
    }

    public function testTheDialogUploadsOneFileARequestAndAnswersJson(): void
    {
        $this->signedIn();
        $token = $this->token('/received-documents/upload');

        $this->client->request('POST', '/received-documents/upload', ['_token' => $token], ['file' => $this->file('%PDF-1.4 one', 'one.pdf')], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(201);
        $answer = $this->json();
        self::assertIsString($answer['id']);
        self::assertSame('/received-documents/'.$answer['id'], $answer['url']);

        $this->client->request('POST', '/received-documents/upload', ['_token' => $token], ['file' => $this->file('%PDF-1.4 one', 'again.pdf')], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(201, 'The same file again only warns.');
        self::assertSame(['The same file is on another document already.'], $this->json()['warnings']);
    }

    public function testADraftShowsItsOriginalBesideTheFormAndIsRecorded(): void
    {
        $this->signedIn();
        $id = $this->draft();

        $crawler = $this->page('/received-documents/'.$id);

        $preview = $crawler->filter('[data-purchasing-original] iframe[data-purchasing-preview="pdf"]');
        self::assertCount(1, $preview);
        self::assertStringStartsWith('/attachments/', (string) $preview->attr('src'));
        self::assertCount(1, $crawler->filter('[data-page-actions] button[form="received-document-form"][value="record"]'));
        self::assertCount(1, $crawler->filter('[data-page-actions] a[href="/received-documents/'.$id.'/trash"][data-dialog]'));
        self::assertCount(1, $crawler->filter('[data-page-actions] form[action="/received-documents/'.$id.'/delete"]'));

        $this->post($id, $this->values($crawler), 'record');

        self::assertResponseRedirects('/received-documents/'.$id, 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Document R20260001 is recorded.', $crawler->filter('main')->text());
        self::assertSame('R20260001', $crawler->filter('main h1')->text());
        $document = $this->document($id);
        self::assertSame(ReceivedDocumentState::Recorded, $document->state());
        self::assertSame('FV-2026-123', $document->supplierNumber());
        self::assertSame(121_000, $document->total()?->minor());
        self::assertSame(21_000, $document->vatCzk()?->minor());
        self::assertStringContainsString('recorded the document', $crawler->filter('[data-purchasing-history]')->text());
        self::assertStringContainsString('1,210.00', $crawler->filter('[data-purchasing-figures]')->text());

        $list = $this->page('/received-documents?tab=recorded');
        self::assertStringContainsString('R20260001', $list->filter('main table tbody')->text());
        self::assertStringContainsString('Supplier Example s.r.o.', $list->filter('main table tbody')->text());
    }

    public function testARefusalIsToldAtItsFieldAndInTheSummary(): void
    {
        $this->signedIn();
        $id = $this->draft();
        $values = ['supplierId' => '', 'supplierQuery' => ''] + $this->values($this->page('/received-documents/'.$id));

        $crawler = $this->post($id, $values, 'record');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Pick the supplier.', $crawler->filter('[data-error-summary]')->text());
        self::assertSame('#supplier', $crawler->filter('[data-error-summary] a')->attr('href'));
        self::assertSame('true', $crawler->filter('#supplier')->attr('aria-invalid'));
        self::assertSame(ReceivedDocumentState::Draft, $this->document($id)->state());
    }

    public function testATypedValueThatIsNoAmountIsToldAtTheRowOfTheRecap(): void
    {
        $this->signedIn();
        $id = $this->draft();
        $values = $this->values($this->page('/received-documents/'.$id));
        $values['recap'] = [['rate' => '21', 'base' => 'a lot', 'vat' => '210.00', 'statedVatCzk' => '']];

        $crawler = $this->post($id, $values, 'save');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#recap-0-base')->attr('aria-invalid'));
        self::assertStringContainsString('Row 1: Base', $crawler->filter('[data-error-summary]')->text());
    }

    public function testRowsOfTheRecapAreAddedAndRemovedWithoutSaving(): void
    {
        $this->signedIn();
        $id = $this->draft();
        $version = $this->document($id)->version();
        $values = $this->values($this->page('/received-documents/'.$id));

        $crawler = $this->post($id, $values, 'add_row');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-recap-row]'));
        $values = $this->values($crawler);
        $crawler = $this->post($id, $values, 'remove_row:0');
        self::assertCount(1, $crawler->filter('[data-recap-row]'));
        self::assertSame('', $crawler->filter('#recap-0-base')->attr('value'));
        self::assertSame($version, $this->document($id)->version(), 'Nothing was saved.');
    }

    public function testARecordedDocumentIsChangedAndItsHistorySaysWhat(): void
    {
        $this->signedIn();
        $id = $this->recorded();
        $values = $this->values($this->page('/received-documents/'.$id));
        $values['supplierNumber'] = 'FV-2026-999';

        $this->post($id, $values, 'save');

        self::assertResponseRedirects('/received-documents/'.$id, 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Document R20260001 is saved.', $crawler->filter('main')->text());
        self::assertSame('FV-2026-999', $this->document($id)->supplierNumber());
        self::assertStringContainsString("changed: Supplier's document number", $crawler->filter('[data-purchasing-history]')->text());
        self::assertCount(0, $crawler->filter('[data-page-actions] button[value="record"]'));
        self::assertCount(0, $crawler->filter('[data-page-actions] form[action$="/delete"]'), 'Only a draft is deleted.');
    }

    public function testAFormOpenedBeforeTheLastSaveIsRefused(): void
    {
        $this->signedIn();
        $id = $this->recorded();
        $values = $this->values($this->page('/received-documents/'.$id));
        $values['version'] = '1';

        $crawler = $this->post($id, $values, 'save');

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('[data-reload-prompt]'));
    }

    public function testWarningsDoNotStopTheSave(): void
    {
        $this->signedIn();
        $first = $this->recorded();
        $second = $this->draft('%PDF-1.4 another file');

        $this->post($second, $this->values($this->page('/received-documents/'.$second)), 'record');

        self::assertResponseRedirects('/received-documents/'.$second, 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString("The supplier's number is on a recorded document already.", $crawler->filter('[data-purchasing-warning]')->text());
        self::assertSame(ReceivedDocumentState::Recorded, $this->document($second)->state());
        self::assertNotSame($first, $second);
    }

    public function testADocumentGoesToTheTrashWithAReasonAndComesBack(): void
    {
        $this->signedIn();
        $id = $this->recorded();
        $token = $this->token('/received-documents/'.$id);

        $this->client->request('POST', '/received-documents/'.$id.'/trash', ['_token' => $token, 'reason' => ''], server: self::DIALOG);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Give a reason.', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/received-documents/'.$id.'/trash', ['_token' => $token, 'reason' => 'Uploaded twice'], server: self::DIALOG);
        self::assertSame(['location' => '/received-documents/'.$id], $this->json());
        $page = $this->page('/received-documents/'.$id);
        self::assertStringContainsString('Uploaded twice', $page->filter('[data-purchasing-trashed]')->text());
        self::assertCount(1, $page->filter('[data-page-actions] form[action="/received-documents/'.$id.'/restore"]'));
        self::assertCount(0, $page->filter('[data-page-actions] button[value="save"]'));
        self::assertStringContainsString('R20260001', $this->page('/received-documents?tab=trash')->filter('main table tbody')->text());

        $this->client->request('POST', '/received-documents/'.$id.'/restore', ['_token' => $token]);

        self::assertResponseRedirects('/received-documents/'.$id, 303);
        self::assertFalse($this->document($id)->isTrashed());
        self::assertStringContainsString('restored the document from the trash', $this->page('/received-documents/'.$id)->filter('[data-purchasing-history]')->text());
    }

    public function testADraftIsDeletedForGood(): void
    {
        $this->signedIn();
        $id = $this->draft();
        $token = $this->token('/received-documents/'.$id);

        $this->client->request('POST', '/received-documents/'.$id.'/delete', ['_token' => $token]);

        self::assertResponseRedirects('/received-documents', 303);
        $this->client->request('GET', '/received-documents/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testARecordedDocumentIsNotDeleted(): void
    {
        $this->signedIn();
        $id = $this->recorded();

        $this->client->request('POST', '/received-documents/'.$id.'/delete', ['_token' => $this->token('/received-documents/'.$id)]);

        self::assertResponseRedirects('/received-documents/'.$id, 303);
        self::assertStringContainsString('The document is recorded already.', $this->client->followRedirect()->filter('main')->text());
    }

    public function testANewDocumentWithoutAFileIsRecordedFromTheDialogWhichLeadsToIt(): void
    {
        $this->signedIn();
        $dialog = $this->dialog('/received-documents/new');
        self::assertCount(1, $dialog->filter('form#received-document-new'));
        self::assertCount(0, $dialog->filter('[name="firm"]'), 'The document goes to the instance firm (ADR 0039).');
        self::assertCount(1, $dialog->filter('[data-lines] button[value="add_line"]'));
        $values = $this->values($dialog);
        $values['lines'] = [['text' => 'Hosting', 'base' => '1000.00', 'rate' => '21']];

        $this->client->request('POST', '/received-documents/new', $values + ['_action' => 'record'], server: self::DIALOG);

        $location = $this->json()['location'] ?? null;
        self::assertIsString($location);
        self::assertMatchesRegularExpression('#^/received-documents/[0-9a-f-]{36}$#', $location);
        $document = $this->document(substr($location, \strlen('/received-documents/')));
        self::assertSame(ReceivedDocumentState::Recorded, $document->state());
        self::assertNull($document->originalAttachmentId());
        self::assertSame(['Hosting'], array_map(static fn (ReceivedDocumentLine $line): string => $line->text(), $document->lines()));
    }

    public function testANewDocumentMissingItsTotalStaysInTheDialog(): void
    {
        $this->signedIn();
        $values = ['total' => ''] + $this->values($this->dialog('/received-documents/new'));

        $this->client->request('POST', '/received-documents/new', $values + ['_action' => 'record'], server: self::DIALOG);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Fill in the total to pay.', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->documentsIn(ReceivedDocumentState::Recorded));
    }

    public function testADocumentInAnotherCurrencyTakesTheRateOfItsTaxPointAndShowsItsDay(): void
    {
        $this->signedIn();
        $id = $this->draft();
        $values = ['currency' => 'EUR', 'kind' => 'foreign_invoice', 'treatment' => 'eu_service'] + $this->values($this->page('/received-documents/'.$id));
        $values['recap'] = [['rate' => '21', 'base' => '100', 'vat' => '0', 'statedVatCzk' => '']];
        $values['total'] = '100';

        $this->post($id, $values, 'record');

        self::assertResponseRedirects('/received-documents/'.$id, 303);
        $document = $this->document($id);
        self::assertSame('24.31', $document->exchangeRate()?->toString());
        self::assertSame(243_100, $document->baseCzk()?->minor());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('ČNB rate published on', $crawler->filter('main')->text());
        $cells = $crawler->filter('[data-purchasing-line] [role="cell"]')->each(static fn (Crawler $cell): string => str_replace("\u{a0}", ' ', trim($cell->text())));
        self::assertSame(['21 %', '100.00 EUR', '2,431.00 Kč'], $cells, 'A line of a document in another currency shows its base in CZK.');
    }

    public function testTheListIsFilteredBySupplierMonthAndText(): void
    {
        $this->signedIn();
        $this->recorded();
        $this->draft('%PDF-1.4 still a draft');

        $found = $this->page('/received-documents?tab=recorded&q=fv-2026&month=2026-10&supplier='.$this->supplier->id());
        self::assertCount(1, $found->filter('main table tbody tr'));
        $none = $this->page('/received-documents?tab=recorded&month=2026-09');
        self::assertCount(0, $none->filter('main table tbody tr'));
        self::assertStringContainsString('No document matches the filter', $none->filter('main')->text());
        $tabs = $found->filter('main nav[aria-label="Received document views"]')->text();
        self::assertStringContainsString('Recorded', $tabs);
    }

    public function testTheSupplierComboboxFindsContacts(): void
    {
        $this->signedIn();

        $this->client->request('GET', '/received-documents/suppliers?q=Supplier');

        self::assertResponseIsSuccessful();
        self::assertSame([['id' => $this->supplier->id(), 'label' => 'Supplier Example s.r.o.', 'hint' => '87654321']], $this->json()['results']);
        $this->client->request('GET', '/received-documents/suppliers?id='.$this->supplier->id());
        self::assertSame($this->supplier->id(), $this->json()['results'][0]['id']);
    }

    public function testAnUnknownDocumentIs404(): void
    {
        $this->signedIn();

        $this->client->request('GET', '/received-documents/'.Id::generate());

        self::assertResponseStatusCodeSame(404);
    }

    public function testAGuestIsKeptOut(): void
    {
        $this->signedIn(Role::Guest);

        foreach (['/received-documents', '/received-documents/upload', '/received-documents/new', '/received-documents/suppliers?q=Supplier', '/received-documents/'.Id::generate()] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        self::assertCount(0, $this->client->request('GET', '/shared')->filter('#sidebar a[href="/received-documents"]'));
    }

    public function testADocumentWithoutLinesShowsItselfAsOneLine(): void
    {
        $this->signedIn();
        $id = $this->recorded();

        $crawler = $this->page('/received-documents/'.$id);

        $lines = $crawler->filter('[data-purchasing-lines]');
        self::assertStringContainsString('The whole document as one line', $lines->text());
        self::assertSame(['Tax invoice', '21 %', '1,000.00 Kč'], $lines->filter('[data-purchasing-line] [role="rowheader"], [data-purchasing-line] [role="cell"]')->each(static fn (Crawler $cell): string => str_replace("\u{a0}", ' ', trim($cell->text()))));
        self::assertCount(0, $crawler->filter('[data-line-row]'), 'The form has no lines of its own.');
        self::assertCount(1, $crawler->filter('[data-lines] button[value="add_line"]'));
    }

    public function testABlankUploadedDraftShowsNoLinesSection(): void
    {
        $this->signedIn();
        $id = $this->draft();

        $crawler = $this->page('/received-documents/'.$id);

        self::assertCount(0, $crawler->filter('[data-purchasing-lines]'));
        self::assertStringNotContainsString('The whole document as one line', $crawler->text());
    }

    public function testLinesAreAddedAndRemovedWithoutSavingAndTellWhatIsLeft(): void
    {
        $this->signedIn();
        $id = $this->recorded();
        $version = $this->document($id)->version();

        $crawler = $this->post($id, $this->values($this->page('/received-documents/'.$id)), 'add_line');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-line-row]'));
        self::assertSame(['21'], $crawler->filter('#lines-0-rate option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        $values = $this->values($crawler);
        $values['lines'] = [['text' => 'Hosting', 'base' => '880.00', 'rate' => '21']];
        $crawler = $this->post($id, $values, 'add_line');
        self::assertCount(2, $crawler->filter('[data-line-row]'));
        self::assertSame('Hosting', $crawler->filter('#lines-0-text')->attr('value'));
        self::assertSame('120.00 Kč left to break down at 21 %', str_replace("\u{a0}", ' ', trim($crawler->filter('[data-lines-remaining]')->text())));

        $crawler = $this->post($id, $this->values($crawler), 'remove_line:0');
        self::assertCount(1, $crawler->filter('[data-line-row]'));
        self::assertSame('', $crawler->filter('#lines-0-text')->attr('value'));
        self::assertSame($version, $this->document($id)->version(), 'Nothing was saved.');
    }

    public function testTheFormPostsTheIdOfEachSavedLineAndRemovingARowKeepsTheOthers(): void
    {
        $this->signedIn();
        $id = $this->recorded();
        $values = $this->values($this->page('/received-documents/'.$id));
        $values['lines'] = [['text' => 'Hosting', 'base' => '700.00', 'rate' => '21'], ['text' => 'Domain', 'base' => '300.00', 'rate' => '21']];
        $this->post($id, $values, 'save');
        self::assertResponseRedirects('/received-documents/'.$id, 303);
        [$hosting, $domain] = array_map(static fn (ReceivedDocumentLine $line): string => $line->id(), $this->document($id)->lines());

        $crawler = $this->page('/received-documents/'.$id);
        self::assertSame([$hosting, $domain], $crawler->filter('[data-line-row] input[type="hidden"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')));

        // A row added has no id; removing the first row keeps the id of the other.
        $crawler = $this->post($id, $this->values($crawler), 'add_line');
        self::assertSame([$hosting, $domain, ''], $crawler->filter('[data-line-row] input[type="hidden"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')));
        $crawler = $this->post($id, $this->values($crawler), 'remove_line:0');
        self::assertSame([$domain, ''], $crawler->filter('[data-line-row] input[type="hidden"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')));

        $values = $this->values($crawler);
        $values['lines'] = [['id' => $domain, 'text' => 'Domain names', 'base' => '300.00', 'rate' => '21'], ['id' => $hosting, 'text' => 'Hosting', 'base' => '700.00', 'rate' => '21']];
        $this->post($id, $values, 'save');
        self::assertResponseRedirects('/received-documents/'.$id, 303);
        self::assertSame([[$domain, 'Domain names'], [$hosting, 'Hosting']], array_map(static fn (ReceivedDocumentLine $line): array => [$line->id(), $line->text()], $this->document($id)->lines()));
    }

    public function testLinesThatDoNotAddUpAreRefusedAtTheLines(): void
    {
        $this->signedIn();
        $id = $this->recorded();
        $version = $this->document($id)->version();
        $values = $this->values($this->page('/received-documents/'.$id));
        $values['lines'] = [['text' => 'Hosting', 'base' => '880.00', 'rate' => '21']];

        $crawler = $this->post($id, $values, 'save');

        self::assertResponseStatusCodeSame(422);
        $message = 'The lines of each VAT rate must add up to the base of that rate in the recap';
        self::assertStringContainsString($message, $crawler->filter('[data-lines] [role="alert"]')->text());
        self::assertSame('#lines', $crawler->filter('[data-error-summary] a')->attr('href'));
        self::assertCount(1, $crawler->filter('#lines'));
        self::assertStringContainsString('120.00', $crawler->filter('[data-lines-remaining]')->text());
        self::assertSame($version, $this->document($id)->version());
        self::assertSame([], $this->document($id)->lines());
    }

    public function testALineWithoutItsTextOrWithNoAmountIsToldAtItsCell(): void
    {
        $this->signedIn();
        $id = $this->recorded();
        $values = $this->values($this->page('/received-documents/'.$id));
        $values['lines'] = [['text' => '', 'base' => '1000.00', 'rate' => '21'], ['text' => 'Support', 'base' => 'a lot', 'rate' => '21']];

        $crawler = $this->post($id, $values, 'save');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#lines-0-text')->attr('aria-invalid'));
        self::assertSame('true', $crawler->filter('#lines-1-base')->attr('aria-invalid'));
        self::assertStringContainsString('Line 2: Base', $crawler->filter('[data-error-summary]')->text());
    }

    public function testBalancedLinesAreSavedAndChangeWithTheRecapInOneSave(): void
    {
        $this->signedIn();
        $id = $this->recorded();
        $values = $this->values($this->page('/received-documents/'.$id));
        $values['lines'] = [['text' => 'Hosting', 'base' => '880.00', 'rate' => '21'], ['text' => 'Support', 'base' => '120.00', 'rate' => '21'], ['text' => '', 'base' => '', 'rate' => '21']];

        $this->post($id, $values, 'save');

        self::assertResponseRedirects('/received-documents/'.$id, 303);
        $crawler = $this->client->followRedirect();
        $document = $this->document($id);
        self::assertSame(['Hosting', 'Support'], array_map(static fn (ReceivedDocumentLine $line): string => $line->text(), $document->lines()), 'A row left empty is no line.');
        self::assertSame([88_000, 12_000], array_map(static fn (ReceivedDocumentLine $line): int => $line->base()->minor(), $document->lines()));
        self::assertStringNotContainsString('The whole document as one line', $crawler->filter('[data-purchasing-lines]')->text());
        self::assertSame(['Hosting', 'Support'], $crawler->filter('[data-purchasing-line] [role="rowheader"]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertCount(2, $crawler->filter('[data-line-row]'));
        self::assertCount(0, $crawler->filter('[data-lines-remaining]'));

        // A new recap needs lines that fit it, in the same save.
        $values = $this->values($crawler);
        $values['total'] = '2420.00';
        $values['recap'] = [['rate' => '21', 'base' => '2000.00', 'vat' => '420.00', 'statedVatCzk' => '']];
        self::assertSame(['880.00', '120.00'], [$crawler->filter('#lines-0-base')->attr('value'), $crawler->filter('#lines-1-base')->attr('value')], 'The form shows the saved lines.');
        $values['lines'] = [['text' => 'Hosting', 'base' => '1880.00', 'rate' => '21'], ['text' => 'Support', 'base' => '120.00', 'rate' => '21']];
        $this->post($id, $values, 'save');
        self::assertResponseRedirects('/received-documents/'.$id, 303);
        self::assertSame([188_000, 12_000], array_map(static fn (ReceivedDocumentLine $line): int => $line->base()->minor(), $this->document($id)->lines()));
        self::assertSame(42_000, $this->document($id)->vatCzk()?->minor(), 'The recap stays the source of the VAT.');

        // Removing every row removes the lines: the whole document is one line again.
        $values = $this->values($this->page('/received-documents/'.$id));
        unset($values['lines']);
        $this->post($id, $values, 'save');
        self::assertResponseRedirects('/received-documents/'.$id, 303);
        self::assertSame([], $this->document($id)->lines());
    }

    public function testEveryRefusalAndWarningIsTranslated(): void
    {
        foreach (['cs', 'en'] as $locale) {
            /** @var array{purchasing: array{refused: array<string, string>, warning: array<string, string>}} $messages */
            $messages = Yaml::parseFile(\dirname(__DIR__, 3).'/src/ReceivedDocuments/translations/messages+intl-icu.'.$locale.'.yaml');
            $warnings = array_values(array_filter(new \ReflectionClass(ReceivedDocumentWarning::class)->getConstants(), \is_string(...)));
            self::assertEqualsCanonicalizing(ReceivedDocumentRefused::REASONS, array_keys($messages['received_documents']['refused']), $locale);
            self::assertEqualsCanonicalizing($warnings, array_keys($messages['received_documents']['warning']), $locale);
        }
    }

    /**
     * @return list<string>
     */
    private function tabs(Crawler $crawler): array
    {
        return $crawler->filter('nav[aria-label="Received document views"] a')->each(static fn (Crawler $tab): string => trim((string) preg_replace('/\s+/', ' ', $tab->text())));
    }

    private function signedIn(Role $role = Role::Owner): void
    {
        $this->browser->seed(role: $role);
        $this->scoped(function (): void {
            $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, '');
            $this->em()->persist($firm);
            $this->supplier = new Party(PartyKind::Company, 'Supplier Example s.r.o.', new RegistrationNumber('CZ', '87654321'), VatId::fromString('CZ87654321'), '');
            $this->em()->persist($this->supplier);
            $this->em()->persist(new PartyAddress($this->supplier, AddressType::Billing, new Address('Supplier Street 2', 'Example City', '110 00', 'CZ')));
            $this->em()->flush();
        });
        $this->browser->signIn();
    }

    /**
     * Posts the files as the form does without JavaScript.
     *
     * @param array<string, string> $files content by name
     */
    private function upload(array $files): void
    {
        $token = $this->token('/received-documents/upload');
        $this->client->request('POST', '/received-documents/upload', ['_token' => $token], ['files' => array_map($this->file(...), $files, array_keys($files))]);
    }

    private function draft(string $content = "%PDF-1.4\nAn example invoice\n%%EOF"): string
    {
        $this->client->request('POST', '/received-documents/upload', ['_token' => $this->token('/received-documents/upload')], ['file' => $this->file($content, 'invoice.pdf')], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'];
        self::assertIsString($id);

        return $id;
    }

    private function recorded(): string
    {
        $id = $this->draft();
        $this->post($id, $this->values($this->page('/received-documents/'.$id)), 'record');
        self::assertResponseRedirects('/received-documents/'.$id, 303);

        return $id;
    }

    /**
     * The values of the form on the page, filled in as a domestic tax invoice of the supplier
     * for 1,000 CZK and 21 % VAT where the form leaves them empty.
     *
     * @return array<string, mixed>
     */
    private function values(Crawler $crawler): array
    {
        $values = [];
        foreach ($crawler->filter('form#received-document-form, form#received-document-new')->form()->getPhpValues() as $field => $value) {
            $values[(string) $field] = $value;
        }
        unset($values['_action']);
        $defaults = [
            'supplierId' => $this->supplier->id(),
            'supplierQuery' => 'Supplier Example s.r.o.',
            'supplierNumber' => 'FV-2026-123',
            'variableSymbol' => '2026123',
            'issueDate' => '2026-10-28',
            'taxPointDate' => '2026-10-28',
            'dueDate' => '2026-11-11',
            'total' => '1210.00',
        ];
        foreach ($defaults as $field => $value) {
            if ('' === ($values[$field] ?? '')) {
                $values[$field] = $value;
            }
        }
        $recap = \is_array($values['recap'] ?? null) ? $values['recap'] : [];
        $first = \is_array($recap[0] ?? null) ? $recap[0] : [];
        if ('' === ($first['base'] ?? '')) {
            $recap[0] = ['rate' => '21', 'base' => '1000.00', 'vat' => '210.00', 'statedVatCzk' => ''];
        }
        $values['recap'] = $recap;

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function post(string $id, array $values, string $action): Crawler
    {
        return $this->client->request('POST', '/received-documents/'.$id, ['_action' => $action] + $values);
    }

    private function token(string $url): string
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $token = $this->client->getCrawler()->filter('main form[action^="/received-documents"] input[name="_token"]')->first()->attr('value');
        self::assertIsString($token);

        return $token;
    }

    private function page(string $url): Crawler
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return $crawler;
    }

    private function dialog(string $url): Crawler
    {
        $this->client->request('GET', $url, server: self::DIALOG);
        self::assertResponseIsSuccessful();

        return new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost/');
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function file(string $content, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'received');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->paths[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function document(string $id): ReceivedDocument
    {
        return $this->scoped(function () use ($id): ReceivedDocument {
            $this->em()->clear();

            return $this->em()->find(ReceivedDocument::class, $id) ?? throw new \LogicException('No such document.');
        });
    }

    private function documentsIn(ReceivedDocumentState $state): int
    {
        return $this->scoped(fn (): int => \count($this->em()->getRepository(ReceivedDocument::class)->findBy(['state' => $state])));
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function scoped(callable $work): mixed
    {
        return $work();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
