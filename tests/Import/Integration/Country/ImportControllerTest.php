<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Import\Integration\Country;

use Foresi\Contacts\Application\CreateParty;
use Foresi\Import\Application\Imports;
use Foresi\Import\Domain\Import;
use Foresi\Import\Domain\ImportState;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Support\Messenger\RunMessages;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The import screens through the browser once every row of the iDoklad export is
 * imported: its domestic account number (a number and a bank code) needs the Czech
 * rules of the Country module.
 */
final class ImportControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string FIXTURE = __DIR__.'/../../../Contacts/Fixtures/idoklad-contacts.csv';

    private KernelBrowser $client;
    private RunMessages $worker;
    private string $dir;

    protected function setUp(): void
    {
        self::mockTime('2026-10-01 10:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $browser = new SignedInBrowser($this->client, $container->get('doctrine.orm.entity_manager'));
        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->worker = new RunMessages($transport, $container->get(MessageBusInterface::class));
        $this->dir = sys_get_temp_dir().'/foresi-import-screens-'.bin2hex(random_bytes(6));
        mkdir($this->dir);

        $browser->seed('en');
        $browser->signIn();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
        parent::tearDown();
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
        self::assertSame('Contacts from iDoklad', self::getContainer()->get(Imports::class)->findFresh($id)?->profile()?->name());
    }

    public function testTheFinishNoticeIsShownUntilTheSummaryIsOpened(): void
    {
        $id = $this->finished();

        $crawler = $this->client->request('GET', '/contacts');
        $notice = $crawler->filter('[data-shell-notices]');
        self::assertStringContainsString('The import of idoklad-contacts.csv ended with errors.', $notice->text());
        self::assertSame('/imports/'.$id, $notice->selectLink('Show result')->attr('href'));

        $crawler = $this->client->request('GET', '/imports/'.$id);
        self::assertSelectorNotExists('[data-shell-notices]');
        self::assertStringContainsString('Result', $crawler->filter('[aria-current="step"]')->text());
        self::assertStringContainsString('1 row could not be imported.', $crawler->filter('[data-import-outcome]')->text());

        $this->client->request('GET', '/contacts');
        self::assertSelectorNotExists('[data-shell-notices]');
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

        $names = $crawler->filter('main tbody tr td:nth-child(2) [data-row-link]')->each(static fn (Crawler $cell): string => trim($cell->text()));
        sort($names);
        self::assertSame(['Example Company s.r.o.', 'Example Trade a.s.', 'Jan Example'], $names);
        self::assertStringContainsString('Contacts the import of idoklad-contacts.csv created.', $crawler->filter('[data-imported-by]')->text());
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

    private function upload(): string
    {
        $path = $this->dir.'/idoklad-contacts.csv';
        file_put_contents($path, (string) file_get_contents(self::FIXTURE));
        $crawler = $this->client->request('GET', '/imports/new?target=party');
        $form = $crawler->selectButton('Upload file')->form();
        $file = $form['file'];
        self::assertInstanceOf(FileFormField::class, $file);
        $file->upload($path);
        $this->client->submit($form);

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/imports/[0-9a-f-]{36}$#', $location);

        return substr($location, \strlen('/imports/'));
    }

    private function uploadAndRead(): string
    {
        $id = $this->upload();
        $this->worker->run();

        return $id;
    }

    private function finished(): string
    {
        $id = $this->uploadAndRead();
        $crawler = $this->client->request('GET', '/imports/'.$id);
        $this->client->submit($crawler->selectButton('Check')->form(['mode' => 'update']));
        $this->worker->run();
        self::assertSame(ImportState::Checked, $this->fresh($id)->state());
        $crawler = $this->client->request('GET', '/imports/'.$id);
        $this->client->submit($crawler->selectButton('Start import')->form());
        $this->worker->run();
        self::assertSame(ImportState::FinishedWithErrors, $this->fresh($id)->state());

        return $id;
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', '/imports/new?target=party');

        return (string) $crawler->filter('form[action^="/imports"][enctype] input[name="_token"]')->attr('value');
    }

    private function fresh(string $id): Import
    {
        return self::getContainer()->get(Imports::class)->findFresh($id) ?? throw new \LogicException('No such import.');
    }

    /**
     * @param list<PartyRole> $roles
     */
    private function party(string $name, array $roles = [PartyRole::Customer]): void
    {
        self::getContainer()->get(CreateParty::class)->create(PartyKind::SoleTrader, $name, null, null, '', '', $roles, new Address('Example Street 1', 'Example City', '100 00', 'CZ'));
    }
}
