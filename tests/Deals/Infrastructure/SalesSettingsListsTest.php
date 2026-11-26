<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Deals\Application\View\DealStageLists;
use Foresi\Deals\Application\View\LostReasonLists;
use Foresi\Deals\Domain\LostReason;
use Foresi\Deals\Domain\Stage;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitions;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Settings → Sales holds two lists with views on one page (list-pages.md, rule 8): the
 * stages on the bare keys of the address, the lost reasons under the "reasons" slot, so
 * changing one table leaves the other as it was.
 */
final class SalesSettingsListsTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $browser = new SignedInBrowser($this->client, $this->em());
        $browser->seed('en');
        $this->em()->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $browser->signIn();
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        foreach (['inquiry', 'meeting'] as $position => $key) {
            $this->em()->persist(new Stage($key, null, $position, $now));
        }
        foreach (['price', 'timing'] as $position => $key) {
            $this->em()->persist(new LostReason($key, null, $position, $now));
        }
        $this->em()->flush();
    }

    public function testEachTableIsAListWithItsOwnKeyAndAllAsItsView(): void
    {
        $stages = self::getContainer()->get(DealStageLists::class);
        $reasons = self::getContainer()->get(LostReasonLists::class);
        $all = self::getContainer()->get(ListDefinitions::class);
        foreach (['sales.deal_stages' => $stages->list(), 'sales.lost_reasons' => $reasons->list()] as $key => $list) {
            self::assertSame($key, $list->key);
            self::assertSame([[ListDefinition::ALL, false, 0]], array_map(static fn (DefaultView $view): array => [$view->slug, $view->showCount, $view->position], $list->defaults));
            self::assertArrayHasKey('name', $list->fields);
            self::assertArrayHasKey('archived', $list->fields);
            // Built once, so its fields are asked once.
            self::assertSame($list, ('sales.deal_stages' === $key ? $stages : $reasons)->list());
            // app:views:sync stores the views of every list it finds.
            self::assertArrayHasKey($key, $all->all());
        }
    }

    public function testTheColumnsOfOneTableLeaveTheOtherAndAnOldLinkStillOpensTheStages(): void
    {
        // An old link of the page names the stages' columns on the bare keys.
        $crawler = $this->client->request('GET', '/settings/sales?cols=name');
        self::assertResponseIsSuccessful();
        self::assertSame(['name'], $this->columns($crawler, 'stages'));
        self::assertSame(['name', 'key', 'state'], $this->columns($crawler, 'reasons'));

        // The reasons' own columns, under their slot, change only the reasons.
        $crawler = $this->client->request('GET', '/settings/sales?cols=name&reasons[cols]=name,state');
        self::assertSame(['name'], $this->columns($crawler, 'stages'));
        self::assertSame(['name', 'state'], $this->columns($crawler, 'reasons'));

        // Each remembers its own for the person: the page opened bare shows both as last changed.
        $crawler = $this->client->request('GET', '/settings/sales');
        self::assertSame(['name'], $this->columns($crawler, 'stages'));
        self::assertSame(['name', 'state'], $this->columns($crawler, 'reasons'));
    }

    public function testTheLinksAndFormsOfOneTableKeepTheOthersState(): void
    {
        $crawler = $this->client->request('GET', '/settings/sales?cols=name&reasons[cols]=name,state&reasons[widths]=name:240');
        $stages = $crawler->filter('[data-sales-stages]');
        $reasons = $crawler->filter('[data-sales-reasons]');

        // The stages' Reset to default forgets their display, and keeps the reasons'.
        $reset = $this->query((string) $stages->filter('[data-column-picker-reset]')->attr('href'));
        self::assertSame(['widths' => '', 'reasons' => ['cols' => 'name,state', 'widths' => 'name:240']], $reset);
        // The reasons' Reset forgets theirs, and keeps the stages'.
        $reset = $this->query((string) $reasons->filter('[data-column-picker-reset]')->attr('href'));
        self::assertSame(['cols' => 'name', 'reasons' => ['widths' => '']], $reset);

        // The forms of the reasons send their fields under the slot and carry the stages' state.
        self::assertCount(1, $reasons->filter('input[type="search"][name="reasons[q]"]'));
        self::assertCount(1, $reasons->filter('form[data-column-picker-form] input[type="hidden"][name="cols"][value="name"]'));
        self::assertCount(1, $reasons->filter('input[name="reasons[cols][]"]')->first());
        self::assertCount(1, $stages->filter('form[data-filter-search] input[name="reasons[cols]"][value="name,state"]'));
        // Each table drags its widths to its own name, and its dialogs have ids of their own.
        self::assertSame('reasons[widths]', $reasons->filter('[data-column-resize-param-value]')->attr('data-column-resize-param-value'));
        self::assertSame('widths', $stages->filter('[data-column-resize-param-value]')->attr('data-column-resize-param-value'));
        self::assertCount(1, $crawler->filter('#view-save-as'));
        self::assertCount(1, $crawler->filter('#view-save-as-reasons'));
        self::assertCount(1, $crawler->filter('#settings-views'));
        self::assertCount(1, $crawler->filter('#settings-views-reasons'));
    }

    public function testTheBuildersRowsOfTheReasonsBecomeTheirFilterAndTheStagesKeepTheirs(): void
    {
        $this->client->request('GET', '/settings/sales?'.http_build_query(['cols' => 'name', 'reasons' => ['conditions' => '1', 'c' => [['field' => 'name', 'op' => 'contains', 'value' => 'Tim']]]]));
        self::assertResponseRedirects();
        $query = $this->query((string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame('name', $query['cols']);
        self::assertIsArray($query['reasons']);
        self::assertArrayNotHasKey('c', $query['reasons']);
        self::assertArrayHasKey('f', $query['reasons']);

        $crawler = $this->client->followRedirect();
        self::assertSame(['Timing'], $this->names($crawler, 'reasons'));
        self::assertSame(['Inquiry', 'Meeting'], $this->names($crawler, 'stages'));
    }

    /**
     * @return list<string>
     */
    private function columns(Crawler $crawler, string $table): array
    {
        return array_values(array_unique($crawler->filter('[data-sales-'.$table.'] colgroup col[data-column]')->each(static fn (Crawler $th): string => (string) $th->attr('data-column'))));
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler, string $table): array
    {
        return $crawler->filter('[data-sales-'.$table.'] tbody tr')->each(static fn (Crawler $row): string => trim($row->filter('th, td')->first()->text()));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return $query;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
