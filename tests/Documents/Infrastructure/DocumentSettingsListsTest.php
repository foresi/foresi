<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Documents\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Documents\Application\View\DocumentTemplateLists;
use Foresi\Documents\Application\View\DocumentTypeLists;
use Foresi\Documents\Domain\DocumentTemplate;
use Foresi\Documents\Domain\DocumentType;
use Foresi\Documents\Domain\SignatureLevel;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Ui\Support\ListPageAssertions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Settings → Documents holds two lists with views on one page (list-pages.md, rule 9): the
 * types on the bare keys of the address, the templates under the "templates" slot, so
 * changing one table leaves the other as it was.
 */
final class DocumentSettingsListsTest extends WebTestCase
{
    use ListPageAssertions;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $browser = new SignedInBrowser($this->client, $this->em());
        $browser->seed('en');
        $browser->signIn();
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        $contract = new DocumentType('Service contract', 'service_contract', true, SignatureLevel::Simple, $now);
        $nda = new DocumentType('Non-disclosure', 'nda', false, SignatureLevel::Simple, $now);
        $this->em()->persist($contract);
        $this->em()->persist($nda);
        $this->em()->persist(new DocumentTemplate($contract, 'Contract 2026', $now));
        $this->em()->persist(new DocumentTemplate($nda, 'Mutual NDA', $now));
        $this->em()->flush();
    }

    public function testEachTableIsAListWithItsOwnKeyAndAllAsItsView(): void
    {
        self::assertListWithViews(self::getContainer()->get(DocumentTypeLists::class), 'documents.types', ['name', 'code', 'signed', 'archived'], ['all' => false]);
        self::assertListWithViews(self::getContainer()->get(DocumentTemplateLists::class), 'documents.templates', ['name', 'type', 'archived'], ['all' => false]);
    }

    public function testTheColumnsOfOneTableLeaveTheOtherAndAnOldLinkStillOpensTheTypes(): void
    {
        // An old link of the page names the types' columns on the bare keys.
        $crawler = $this->client->request('GET', '/settings/documents?cols=name');
        self::assertResponseIsSuccessful();
        self::assertSame(['name'], $this->columns($crawler, 'types'));
        self::assertSame(['name', 'type', 'version'], $this->columns($crawler, 'templates'));

        $crawler = $this->client->request('GET', '/settings/documents?cols=name&templates[cols]=name,type');
        self::assertSame(['name'], $this->columns($crawler, 'types'));
        self::assertSame(['name', 'type'], $this->columns($crawler, 'templates'));

        // Each remembers its own for the person.
        $crawler = $this->client->request('GET', '/settings/documents');
        self::assertSame(['name'], $this->columns($crawler, 'types'));
        self::assertSame(['name', 'type'], $this->columns($crawler, 'templates'));
    }

    public function testTheResetAndFormsOfOneTableKeepTheOthersState(): void
    {
        $crawler = $this->client->request('GET', '/settings/documents?cols=name&templates[cols]=name,type&templates[widths]=name:240');

        self::assertListTable($crawler, '[data-document-types]', ['widths' => '', 'templates' => ['cols' => 'name,type', 'widths' => 'name:240']]);
        self::assertListTable($crawler, '[data-document-templates]', ['cols' => 'name', 'templates' => ['widths' => '']], 'templates[widths]');
        $templates = $crawler->filter('[data-document-templates]');
        self::assertCount(1, $templates->filter('input[type="search"][name="templates[q]"]'));
        self::assertCount(1, $crawler->filter('[data-document-types] form[data-filter-search] input[name="templates[cols]"][value="name,type"]'));
        self::assertCount(1, $crawler->filter('#view-save-as'));
        self::assertCount(1, $crawler->filter('#view-save-as-templates'));
    }

    public function testTheSearchAndBuilderOfTheTemplatesLeaveTheTypes(): void
    {
        $crawler = $this->client->request('GET', '/settings/documents?templates[q]=mutual');
        self::assertSame(['Mutual NDA'], $this->names($crawler, 'templates'));
        self::assertSame(['Non-disclosure', 'Service contract'], $this->names($crawler, 'types'));

        $crawler = self::applyBuilder($this->client, '/settings/documents?cols=name', 'name', 'Contract', 'templates');
        self::assertSame(['Contract 2026'], $this->names($crawler, 'templates'));
        self::assertSame(['name'], $this->columns($crawler, 'types'));

        // The types filter on whether their documents are signed.
        $this->client->request('GET', '/settings/documents?'.http_build_query(['conditions' => '1', 'c' => [['field' => 'signed', 'op' => 'is', 'value' => '1']]]));
        self::assertSame(['Service contract'], $this->names($this->client->followRedirect(), 'types'));
    }

    /**
     * @return list<string>
     */
    private function columns(Crawler $crawler, string $table): array
    {
        return $crawler->filter('[data-document-'.$table.'] colgroup col[data-column]')->each(static fn (Crawler $col): string => (string) $col->attr('data-column'));
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler, string $table): array
    {
        $names = $crawler->filter('[data-document-'.$table.'] tbody tr[data-id]')->each(static fn (Crawler $row): string => trim($row->filter('th, td')->first()->text()));
        sort($names);

        return $names;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
