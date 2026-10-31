<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Infrastructure;

use App\Import\Application\Imports;
use App\Import\Application\Message\RunImportChunk;
use App\Import\Application\Source\SourceFormat;
use App\Import\Application\Source\SourceSettings;
use App\Import\Application\StartImport;
use App\Import\Domain\Import;
use App\Import\Domain\ImportState;
use App\Import\Infrastructure\Source\OpenSpoutSourceReader;
use App\Party\Application\CreateParty;
use App\Party\Domain\Address;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Infrastructure\Import\PartyImportTarget;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Tests\Import\Support\RunMessages;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The import screens through the browser, with the background messages
 * handled by the in-memory transport as a worker would.
 */
final class ImportControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string FIXTURE = __DIR__.'/../../Party/Fixtures/idoklad-contacts.csv';

    private KernelBrowser $client;
    private TenantContext $context;
    private RunMessages $worker;
    private InMemoryTransport $transport;
    private TenantId $tenant;
    private TenantId $foreign;
    private string $dir;

    protected function setUp(): void
    {
        self::mockTime('2026-10-01 10:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->context = $container->get(TenantContext::class);
        $browser = new SignedInBrowser($this->client, $container->get('doctrine.orm.entity_manager'), $this->context);
        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->transport = $transport;
        $this->worker = new RunMessages($transport, $container->get(MessageBusInterface::class));
        $this->dir = sys_get_temp_dir().'/foresi-import-screens-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        [$this->tenant] = $browser->seed(['Tenant A'], 'en');
        $this->foreign = $browser->fixtures->tenant('Foreign Tenant');
        $browser->signIn();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
        parent::tearDown();
    }

    public function testTheAddressBookOffersImportAndExport(): void
    {
        $this->party('Example Company s.r.o.');

        $crawler = $this->client->request('GET', '/contacts');

        $actions = $crawler->filter('[data-page-actions]');
        self::assertSame('/imports/new?target=party', $actions->selectLink('Import')->attr('href'));
        self::assertCount(1, $actions->filter('form[action="/contacts/export"] #export-button'));
        self::assertStringContainsString('New contact', $actions->filter('a.bg-green')->text());
    }

    public function testTheHeaderExportsTheWholeViewAsItIsFiltered(): void
    {
        $this->party('Example Company s.r.o.');
        $this->party('Example Supplies a.s.', [PartyRole::Supplier]);

        $crawler = $this->client->request('GET', '/contacts?view=suppliers');
        $this->client->submit($crawler->filter('[data-page-actions] form')->form());

        self::assertResponseIsSuccessful();
        $csv = $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString('Example Supplies a.s.', $csv);
        self::assertStringNotContainsString('Example Company s.r.o.', $csv);
    }

    public function testTheHeaderOffersBothFormatsAndExportsTheSearchedViewAsAWorkbook(): void
    {
        $this->party('Example Company s.r.o.');
        $this->party('Example Supplies a.s.', [PartyRole::Supplier]);

        $crawler = $this->client->request('GET', '/contacts?q=Supplies');
        $menu = $crawler->filter('[data-page-actions] #export-menu');
        self::assertSame(['csv', 'xlsx'], $menu->filter('button[name="format"]')->each(static fn (Crawler $item): string => (string) $item->attr('value')));
        $this->client->submit($menu->filter('button[value="xlsx"]')->form());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spreadsheetml', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $path = tempnam(sys_get_temp_dir(), 'foresi-xlsx');
        file_put_contents($path, $this->client->getInternalResponse()->getContent());
        try {
            $rows = [...new OpenSpoutSourceReader()->rows($path, new SourceSettings(SourceFormat::Xlsx, headerRow: 1))];
        } finally {
            unlink($path);
        }
        self::assertCount(1, $rows);
        self::assertContains('Example Supplies a.s.', $rows[0]);
    }

    public function testAnUploadedFileIsReadAndItsColumnsAreProposed(): void
    {
        $id = $this->upload();

        self::assertResponseRedirects('/imports/'.$id, 303);
        // The file is read in the background; until then the page shows the progress.
        $held = $this->held();
        $crawler = $this->client->request('GET', '/imports/'.$id);
        self::assertSelectorExists('turbo-frame#import-progress[data-controller="import-progress"]');
        self::assertSame('true', $crawler->filter('[data-progress-active]')->attr('data-progress-active'));

        $this->resume($held);
        $crawler = $this->client->request('GET', '/imports/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'idoklad-contacts.csv');
        self::assertStringContainsString('Columns', $crawler->filter('nav[aria-label="Import steps"] [aria-current="step"]')->text());
        $firma = $this->row($crawler, 'Firma');
        self::assertSame('display_name', $firma->filter('option[selected]')->attr('value'));
        self::assertSame('iDoklad', $firma->filter('.bg-badge-green')->text());
        self::assertSame(['Example Company s.r.o.', 'Jan Example', 'Example Trade a.s.'], $firma->filter('p [title]')->each(static fn (Crawler $sample): string => $sample->text()));
        // A column the preset leaves out is proposed as "do not import", and says why.
        $discount = $this->row($crawler, 'Sleva');
        self::assertSame('', $discount->filter('option[selected]')->attr('value'));
        self::assertSame('iDoklad', $discount->filter('.bg-badge-green')->text());
        self::assertSame(['Contact', 'Billing address', 'Delivery address', 'Bank account', 'Contact person'], $firma->filter('optgroup')->each(static fn (Crawler $group): string => (string) $group->attr('label')));
        self::assertCount(3, $crawler->filter('input[type="radio"][name="mode"]'));
        self::assertSelectorTextContains('label[for="mode-full_replace"]', 'Records that are not in the file are archived.');
        self::assertNotNull($crawler->filter('[data-missing-alert]')->attr('hidden'));
    }

    public function testAMissingRequiredFieldBlocksTheCheckAndNamesTheField(): void
    {
        $id = $this->uploadAndRead();
        $crawler = $this->client->request('GET', '/imports/'.$id);
        $form = $crawler->selectButton('Check')->form();
        $form->setValues(['columns[0]' => '']);

        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main [role="alert"]', 'Name');
        self::assertSame(ImportState::Mapping, $this->fresh($id)->state());
        // The choice the user made is kept, and the warning above the columns names the field.
        self::assertSame('', $this->row($crawler, 'Firma')->filter('option[selected]')->attr('value'));
        self::assertNull($crawler->filter('[data-missing-alert]')->attr('hidden'));
        self::assertStringContainsString('Name', $crawler->filter('[data-missing-alert]')->text());
        self::assertSame('{"display_name":"Name"}', $crawler->filter('form[data-controller="import-mapping"]')->attr('data-import-mapping-required-value'));
    }

    public function testTheMappingIsCheckedAndTheCheckShowsWhatTheImportWillDo(): void
    {
        $id = $this->uploadAndRead();
        $crawler = $this->client->request('GET', '/imports/'.$id);
        $this->client->submit($crawler->selectButton('Check')->form(['mode' => 'update', 'profile_name' => 'Contacts from iDoklad']));
        self::assertResponseRedirects('/imports/'.$id, 303);
        $this->worker->run();

        $crawler = $this->client->request('GET', '/imports/'.$id);

        self::assertStringContainsString('Check', $crawler->filter('[aria-current="step"]')->text());
        $kpis = $crawler->filter('[data-kpi]')->each(static fn (Crawler $kpi): string => (string) preg_replace('/\s+/', ' ', trim($kpi->text())));
        self::assertSame(['To create 3', 'To update 0', 'To skip 0', 'With errors 1 Not imported'], $kpis);
        self::assertSame(['New', 'With errors'], $crawler->filter('nav[aria-label="Rows preview"] a')->each(static fn (Crawler $tab): string => trim($tab->text(), " 0123456789\n")));
        self::assertCount(3, $crawler->filter('main tbody tr'));

        $crawler = $this->client->request('GET', '/imports/'.$id.'?outcome=error');
        self::assertCount(1, $crawler->filter('[data-error-rows] > li'));
        self::assertStringContainsString('Row 5', $crawler->filter('[data-error-rows] > li')->text());
        self::assertStringContainsString('Column IČ. "12345678" is not a valid', $crawler->filter('[data-error-rows] > li')->text());
        self::assertSelectorExists('a[href="/imports/'.$id.'/errors"]');
        $this->context->switchTo($this->tenant);
        self::assertSame('Contacts from iDoklad', self::getContainer()->get(Imports::class)->findFresh($id)?->profile()?->name());
        $this->context->reset();
    }

    public function testAFullReplaceRunsOnlyWithTheNumberToArchiveTyped(): void
    {
        $this->party('Example Old Customer');
        $id = $this->uploadAndRead();
        $crawler = $this->client->request('GET', '/imports/'.$id);
        $this->client->submit($crawler->selectButton('Check')->form(['mode' => 'full_replace', 'scope' => 'all']));
        $this->worker->run();

        $crawler = $this->client->request('GET', '/imports/'.$id);
        self::assertSelectorTextContains('[data-archive-warning]', '1 record will be archived');
        $this->client->submit($crawler->selectButton('Start import')->form(['confirmation' => '2']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#confirmation-error', 'Type 1');
        self::assertSame(ImportState::Checked, $this->fresh($id)->state());

        $crawler = $this->client->request('GET', '/imports/'.$id);
        $this->client->submit($crawler->selectButton('Start import')->form(['confirmation' => '1']));
        self::assertResponseRedirects('/imports/'.$id, 303);
        $this->worker->run();
        self::assertSame(1, $this->fresh($id)->archived());

        // The summary's archive link lists the record the full replace archived, and nothing else.
        $crawler = $this->client->request('GET', '/contacts?import='.$id.'&outcome=archive');
        self::assertSame(['Example Old Customer'], $crawler->filter('main tbody tr td:nth-child(2)')->each(static fn (Crawler $cell): string => trim($cell->text())));
    }

    public function testRunningTwiceStartsTheImportOnce(): void
    {
        $id = $this->checked();
        $crawler = $this->client->request('GET', '/imports/'.$id);
        $form = $crawler->selectButton('Start import')->form();

        $this->client->submit($form);
        self::assertResponseRedirects('/imports/'.$id, 303);
        $first = $this->held();
        $this->client->submit($form);
        self::assertResponseRedirects('/imports/'.$id, 303);

        // The test client resets the in-memory queue between requests, so each request's messages are counted alone.
        self::assertSame(1, $this->runChunks($first));
        self::assertSame(0, $this->runChunks($this->held()));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Run the check before you start the import.', $crawler->filter('main')->text());
    }

    public function testTheProgressFrameShowsTheCounters(): void
    {
        $rows = ['Firma,E-mailová adresa'];
        for ($i = 1; $i <= 1200; ++$i) {
            $rows[] = \sprintf('Example Company %d,contact%d@example.com', $i, $i);
        }
        $id = $this->checked(implode("\n", $rows));
        $crawler = $this->client->request('GET', '/imports/'.$id);
        $this->client->submit($crawler->selectButton('Start import')->form());
        $this->worker->run(1);
        $held = $this->held();

        $crawler = $this->client->request('GET', '/imports/'.$id.'/progress');

        self::assertResponseIsSuccessful();
        self::assertSame('import-progress', $crawler->filter('turbo-frame')->attr('id'));
        self::assertSame('true', $crawler->filter('[data-progress-active]')->attr('data-progress-active'));
        $bar = $crawler->filter('[role="progressbar"]');
        self::assertSame('500', $bar->attr('aria-valuenow'));
        self::assertSame('1200', $bar->attr('aria-valuemax'));
        self::assertSame('500 of 1,200 rows', $bar->attr('aria-valuetext'));

        $this->resume($held);
        $crawler = $this->client->request('GET', '/imports/'.$id.'/progress');
        self::assertSame('false', $crawler->filter('[data-progress-active]')->attr('data-progress-active'));
    }

    public function testTheFinishNoticeIsShownUntilTheSummaryIsOpened(): void
    {
        $id = $this->finished();

        $crawler = $this->client->request('GET', '/contacts');
        $notice = $crawler->filter('[data-shell-notices]');
        self::assertStringContainsString('The import of idoklad-contacts.csv ended with errors.', $notice->text());
        self::assertSame('/imports/'.$id, $notice->selectLink('Show summary')->attr('href'));

        $crawler = $this->client->request('GET', '/imports/'.$id);
        self::assertSelectorNotExists('[data-shell-notices]');
        self::assertStringContainsString('Summary', $crawler->filter('[aria-current="step"]')->text());
        self::assertStringContainsString('1 row could not be imported.', $crawler->filter('[data-import-outcome]')->text());

        $this->client->request('GET', '/contacts');
        self::assertSelectorNotExists('[data-shell-notices]');
    }

    public function testTheErrorFileIsACsvNamedAfterTheImport(): void
    {
        $id = $this->finished();

        $this->client->request('GET', '/imports/'.$id.'/errors');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=import-'.$id.'-errors.csv', $response->headers->get('Content-Disposition'));
        $content = $this->client->getInternalResponse()->getContent();
        self::assertStringContainsString('Example Invalid', $content);
        self::assertStringNotContainsString('Jan Example', $content);
    }

    public function testTheSummaryLinksToTheCreatedContactsAndTheListShowsExactlyThem(): void
    {
        $this->party('Example Seeded Contact');
        $id = $this->finished();

        $crawler = $this->client->request('GET', '/imports/'.$id);
        $created = $crawler->filter('a[data-kpi]')->first();
        self::assertStringContainsString('Created', $created->text());
        $href = (string) $created->attr('href');
        self::assertSame('/contacts?import='.$id.'&outcome=create', $href);

        $crawler = $this->client->request('GET', $href);

        $names = $crawler->filter('main tbody tr td:nth-child(2)')->each(static fn (Crawler $cell): string => trim($cell->text()));
        sort($names);
        self::assertSame(['Example Company s.r.o.', 'Example Trade a.s.', 'Jan Example'], $names);
        self::assertStringContainsString('Contacts the import of idoklad-contacts.csv created.', $crawler->filter('[data-imported-by]')->text());
    }

    public function testAnImportOfAnotherTenantDoesNotNarrowTheList(): void
    {
        $this->party('Example Seeded Contact');
        $id = $this->foreignImport();

        $crawler = $this->client->request('GET', '/contacts?import='.$id.'&outcome=create');

        self::assertSelectorNotExists('[data-imported-by]');
        self::assertCount(1, $crawler->filter('main tbody tr'));
    }

    public function testTheUndoButtonIsGoneThirtyDaysAfterTheImport(): void
    {
        $id = $this->finished();
        $crawler = $this->client->request('GET', '/imports/'.$id);
        self::assertCount(1, $crawler->filter('form[action="/imports/'.$id.'/undo"]'));
        self::assertStringContainsString('until 2026-10-31', $crawler->filter('main')->text());

        self::mockTime('2026-11-01 10:00:01');
        $crawler = $this->client->request('GET', '/imports/'.$id);

        self::assertCount(0, $crawler->filter('form[action="/imports/'.$id.'/undo"]'));
        self::assertStringContainsString('That time has passed.', $crawler->filter('main')->text());
    }

    public function testAFailedImportOffersTheUndoOnlyWhenItWroteRows(): void
    {
        $id = $this->finished();
        // As the health check leaves an undo whose worker died.
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE import SET state = 'failed', failure_message_key = 'import.failure.stalled' WHERE id = ?", [$id]);

        $crawler = $this->client->request('GET', '/imports/'.$id);

        self::assertCount(1, $crawler->filter('form[action="/imports/'.$id.'/undo"]'));
        $this->client->submit($crawler->filter('form[action="/imports/'.$id.'/undo"]')->form());
        $this->worker->run();
        self::assertSame(ImportState::Undone, $this->fresh($id)->state());

        $unread = $this->upload("Firma\n");
        $this->worker->run();
        self::assertSame(ImportState::Failed, $this->fresh($unread)->state());
        $crawler = $this->client->request('GET', '/imports/'.$unread);
        self::assertCount(0, $crawler->filter('form[action="/imports/'.$unread.'/undo"]'));
    }

    public function testUndoingPutsTheContactsBackAndTheSummarySaysSo(): void
    {
        $id = $this->finished();
        $crawler = $this->client->request('GET', '/imports/'.$id);
        $this->client->submit($crawler->filter('form[action="/imports/'.$id.'/undo"]')->form());
        self::assertResponseRedirects('/imports/'.$id, 303);
        $this->worker->run();

        $crawler = $this->client->request('GET', '/imports/'.$id);

        self::assertSame(ImportState::Undone, $this->fresh($id)->state());
        self::assertStringContainsString('The import is undone.', $crawler->filter('[data-import-outcome]')->text());
        $kpis = $crawler->filter('[data-kpi]')->each(static fn (Crawler $kpi): string => (string) preg_replace('/\s+/', ' ', trim($kpi->text())));
        self::assertSame(['Undone 3', 'Changed after the import 0'], $kpis);

        // A second undo is refused and changes nothing.
        $this->client->request('POST', '/imports/'.$id.'/undo', ['_token' => $this->token()]);
        self::assertResponseRedirects('/imports/'.$id, 303);
        self::assertSame(ImportState::Undone, $this->fresh($id)->state());
    }

    public function testCancellingEndsTheImportWithoutANotice(): void
    {
        $id = $this->uploadAndRead();
        $crawler = $this->client->request('GET', '/imports/'.$id);
        $this->client->submit($crawler->filter('form[action="/imports/'.$id.'/cancel"]')->form());
        self::assertResponseRedirects('/imports/'.$id, 303);

        $crawler = $this->client->followRedirect();

        self::assertSame(ImportState::Cancelled, $this->fresh($id)->state());
        self::assertStringContainsString('The import is cancelled.', $crawler->filter('main')->text());
        $this->client->request('GET', '/contacts');
        self::assertSelectorNotExists('[data-shell-notices]');
    }

    public function testTheHistoryListsTheImportsOfTheTenantOnly(): void
    {
        $id = $this->finished();
        $this->foreignImport();

        $crawler = $this->client->request('GET', '/imports');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('main tbody tr'));
        $cells = $crawler->filter('main tbody tr td')->each(static fn (Crawler $cell): string => trim($cell->text()));
        self::assertSame(['idoklad-contacts.csv', '2026-10-01 10:00', 'Example Company', 'Contacts', 'Update existing', '3', '0', '1', 'Done with errors'], $cells);
        self::assertSame('/imports/'.$id, $crawler->filter('main tbody a')->attr('href'));
    }

    public function testAnImportOfAnotherTenantIsNotFound(): void
    {
        $id = $this->foreignImport();

        foreach (['/imports/'.$id, '/imports/'.$id.'/progress', '/imports/'.$id.'/errors'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
        $token = $this->token();
        foreach (['run', 'cancel', 'undo', 'check', 'mapping', 'source'] as $action) {
            $this->client->request('POST', '/imports/'.$id.'/'.$action, ['_token' => $token]);
            self::assertResponseStatusCodeSame(404, $action);
        }
    }

    public function testActionsNeedTheCsrfToken(): void
    {
        $id = $this->uploadAndRead();

        $this->client->request('POST', '/imports/'.$id.'/cancel', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(ImportState::Mapping, $this->fresh($id)->state());
    }

    public function testAnUploadWithoutAFileIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/imports/new?target=party');
        self::assertStringContainsString('Upload', $crawler->filter('[aria-current="step"]')->text());

        $this->client->request('POST', '/imports', ['_token' => $crawler->filter('form[action^="/imports"][enctype] input[name="_token"]')->attr('value'), 'target' => 'party']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#file-error', 'Choose the file you want to upload.');
    }

    public function testALongFileNameIsCutToTheColumnAndKeepsItsExtension(): void
    {
        $path = $this->dir.'/upload.csv';
        file_put_contents($path, (string) file_get_contents(self::FIXTURE));
        $name = str_repeat('Příliš žluťoučký kůň ', 15).'.csv';
        self::assertGreaterThan(300, mb_strlen($name));

        $this->client->request('POST', '/imports?target=party', ['_token' => $this->token(), 'target' => 'party'], ['file' => new UploadedFile($path, $name, 'text/csv', null, true)]);

        self::assertResponseStatusCodeSame(303);
        $id = (int) substr((string) $this->client->getResponse()->headers->get('Location'), \strlen('/imports/'));
        $stored = $this->fresh($id)->originalName();
        self::assertSame(255, mb_strlen($stored));
        self::assertStringEndsWith('.csv', $stored);
    }

    public function testAFileTheServerLimitStoppedIsReportedAsTheServerLimit(): void
    {
        $token = $this->token();
        $this->client->request('POST', '/imports?target=party', ['_token' => $token, 'target' => 'party'], ['file' => new UploadedFile(self::FIXTURE, 'big.csv', 'text/csv', \UPLOAD_ERR_INI_SIZE, true)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#file-error', 'larger than the server accepts');
        self::assertSelectorTextNotContains('#file-error', '50 MB');
    }

    public function testABodyOverThePostLimitIsReportedAsTheServerLimit(): void
    {
        // PHP drops a body over post_max_size: no fields, no files, only the length.
        $this->client->request('POST', '/imports?target=party', [], [], ['CONTENT_LENGTH' => '104857600', 'CONTENT_TYPE' => 'multipart/form-data; boundary=x']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#file-error', 'larger than the server accepts');
        self::assertSelectorExists('input[type="hidden"][name="target"][value="party"]');
    }

    public function testTheHeaderExportOfAListNarrowedToAnImportExportsExactlyThatList(): void
    {
        $this->party('Example Seeded Contact');
        $id = $this->finished();

        $crawler = $this->client->request('GET', '/contacts?import='.$id.'&outcome=create');
        $this->client->submit($crawler->filter('[data-page-actions] form')->form());

        $csv = $this->client->getInternalResponse()->getContent();
        foreach (['Example Company s.r.o.', 'Example Trade a.s.', 'Jan Example'] as $name) {
            self::assertStringContainsString($name, $csv);
        }
        self::assertStringNotContainsString('Example Seeded Contact', $csv);
    }

    public function testDownloadsAreNeverSniffed(): void
    {
        $id = $this->finished();

        $this->client->request('GET', '/imports/'.$id.'/errors');

        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testAnUnknownTargetIsNotFound(): void
    {
        $this->client->request('GET', '/imports/new?target=nothing');

        self::assertResponseStatusCodeSame(404);
    }

    private function upload(string $csv = ''): int
    {
        $path = $this->dir.'/idoklad-contacts.csv';
        file_put_contents($path, '' === $csv ? (string) file_get_contents(self::FIXTURE) : $csv);
        $crawler = $this->client->request('GET', '/imports/new?target=party');
        $form = $crawler->selectButton('Upload file')->form();
        $file = $form['file'];
        self::assertInstanceOf(FileFormField::class, $file);
        $file->upload($path);
        $this->client->submit($form);

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/imports/\d+$#', $location);

        return (int) substr($location, \strlen('/imports/'));
    }

    private function uploadAndRead(string $csv = ''): int
    {
        $id = $this->upload($csv);
        $this->worker->run();

        return $id;
    }

    private function checked(string $csv = ''): int
    {
        $id = $this->uploadAndRead($csv);
        $crawler = $this->client->request('GET', '/imports/'.$id);
        $this->client->submit($crawler->selectButton('Check')->form(['mode' => 'update']));
        $this->worker->run();
        self::assertSame(ImportState::Checked, $this->fresh($id)->state());

        return $id;
    }

    private function finished(): int
    {
        $id = $this->checked();
        $crawler = $this->client->request('GET', '/imports/'.$id);
        $this->client->submit($crawler->selectButton('Start import')->form());
        $this->worker->run();
        self::assertSame(ImportState::FinishedWithErrors, $this->fresh($id)->state());

        return $id;
    }

    private function foreignImport(): int
    {
        $path = $this->dir.'/foreign.csv';
        file_put_contents($path, (string) file_get_contents(self::FIXTURE));
        $this->context->switchTo($this->foreign);
        $id = self::getContainer()->get(StartImport::class)->start(PartyImportTarget::KEY, $path, 'foreign.csv', 1);
        $this->worker->run();
        $this->context->reset();

        return $id;
    }

    /**
     * The messages waiting now. The test client empties the in-memory queue on
     * the next request, so a test that looks at a page between two steps of
     * the worker keeps them and delivers them afterwards.
     *
     * @return list<Envelope>
     */
    private function held(): array
    {
        return array_values([...$this->transport->get()]);
    }

    /**
     * @param list<Envelope> $envelopes
     */
    private function resume(array $envelopes): void
    {
        foreach ($envelopes as $envelope) {
            $this->worker->redeliver($envelope);
        }
        $this->worker->run();
    }

    /**
     * @param list<Envelope> $envelopes
     */
    private function runChunks(array $envelopes): int
    {
        return \count(array_filter($envelopes, static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof RunImportChunk));
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', '/imports/new?target=party');

        return (string) $crawler->filter('form[action^="/imports"][enctype] input[name="_token"]')->attr('value');
    }

    private function row(Crawler $crawler, string $header): Crawler
    {
        $label = $crawler->filter('label[title="'.$header.'"]');
        self::assertCount(1, $label, \sprintf('No mapping row for the column "%s".', $header));

        return $crawler->filter('#'.$label->attr('for'))->closest('div.grid') ?? throw new \LogicException('The row has no container.');
    }

    private function fresh(int $id): Import
    {
        $this->context->switchTo($this->tenant);
        $import = self::getContainer()->get(Imports::class)->findFresh($id) ?? throw new \LogicException('No such import.');
        $this->context->reset();

        return $import;
    }

    /**
     * @param list<PartyRole> $roles
     */
    private function party(string $name, array $roles = [PartyRole::Customer]): void
    {
        $this->context->switchTo($this->tenant);
        self::getContainer()->get(CreateParty::class)->create(PartyKind::SoleTrader, $name, null, null, '', '', $roles, new Address('Example Street 1', 'Example City', '100 00', 'CZ'));
        $this->context->reset();
    }
}
