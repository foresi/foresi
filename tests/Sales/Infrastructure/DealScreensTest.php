<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Catalog\Domain\Product;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Application\LinkContactPerson;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Domain\DealLink;
use Foresi\Sales\Domain\DealStatus;
use Foresi\Sales\Domain\LostReason;
use Foresi\Sales\Domain\Stage;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The screens of Sales: the deals grouped by stage on saved views, a new deal
 * in a dialog (from the list and from a contact), the deal's page with Won,
 * Lost (a reason from the list and a note), Reopen, the stage, a follow-up
 * and its lines from the catalog, and Settings → Sales with the stages in
 * their order and an archive refused while open deals sit in a stage.
 */
final class DealScreensTest extends WebTestCase
{
    private const string HEADER = 'HTTP_X_FORESI_DIALOG';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    /** @var array<string, Stage> by key */
    private array $stages = [];
    /** @var array<string, LostReason> by key */
    private array $reasons = [];
    private Party $company;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, $this->em());
        if ('testAGuestIsRefused' === $this->name()) {
            return;
        }
        $this->browser->seed('en');
        $this->firm();
        $this->browser->signIn();
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        foreach (['inquiry', 'meeting', 'proposal', 'negotiation'] as $position => $key) {
            $this->em()->persist($this->stages[$key] = new Stage($key, null, $position, $now));
        }
        foreach (['price', 'timing'] as $position => $key) {
            $this->em()->persist($this->reasons[$key] = new LostReason($key, null, $position, $now));
        }
        $this->company = new Party(PartyKind::Company, 'Alpha Example s.r.o.', new RegistrationNumber('CZ', '87654321'), null, '', [PartyRole::Customer]);
        $petra = new Party(PartyKind::Person, 'Petra Example', null, null, '');
        $this->em()->persist($this->company);
        $this->em()->persist($petra);
        $this->em()->flush();
        self::getContainer()->get(LinkContactPerson::class)->link($this->company, $petra, '', false);
    }

    public function testTheListGroupsTheDealsByStageOnSavedViews(): void
    {
        $crawler = $this->client->request('GET', '/sales/deals');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Deals');
        self::assertSelectorTextContains('body', 'No deals yet');
        self::assertSame('/sales/deals/new', $crawler->filter('header #deal-new[data-dialog]')->attr('href'));
        self::assertGreaterThan(0, $crawler->filter('nav a[href="/sales/deals"][aria-current="page"]')->count());
        self::assertSame(['All', 'Open', 'Won', 'Lost', 'Trash'], $crawler->filter('nav[aria-label="Views of the deals"] a')->each(static fn (Crawler $tab): string => trim($tab->text())));

        $audit = $this->newDeal('Roof audit');
        $this->newDeal('Gutter repair', $this->stages['proposal']);
        $this->newDeal('Chimney check');
        $this->client->request('POST', '/sales/deals/'.$audit->id().'/win', ['_token' => $this->token()]);

        $crawler = $this->client->request('GET', '/sales/deals');
        // One table per stage that holds a deal, in the order of the stages, with its count.
        self::assertSame([$this->stages['inquiry']->id(), $this->stages['proposal']->id()], $crawler->filter('[data-deal-stage]')->each(static fn (Crawler $section): string => (string) $section->attr('data-deal-stage')));
        self::assertStringContainsString('2 deals', $crawler->filter('[data-deal-stage]')->first()->filter('h2')->text());
        self::assertStringContainsString('Chimney check', $crawler->filter('[data-deal-stage]')->first()->text());
        self::assertStringContainsString('Roof audit', $crawler->filter('[data-deal-stage]')->first()->text());
        self::assertSelectorTextContains('[data-deal-stage="'.$this->stages['proposal']->id().'"]', 'Gutter repair');

        // The Won view holds the deal won only.
        $this->client->request('GET', '/sales/deals?view=won');
        self::assertSelectorTextContains('main', 'Roof audit');
        self::assertSelectorTextNotContains('main', 'Gutter repair');
    }

    public function testANewDealFromAContactHasItsClientAndContactPersons(): void
    {
        $crawler = $this->client->request('GET', '/sales/deals/new?client='.$this->company->id(), server: [self::HEADER => '1']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('dialog#dialog-page [data-deal-client]', 'Alpha Example s.r.o.');
        $form = $crawler->filter('#deal-form')->form();
        $form['title'] = 'Roof audit';
        $this->choices($form['contactPersonIds'])[0]->tick();
        $this->choice($form['stageId'])->select($this->stages['meeting']->id());
        $this->client->submit($form, [], [self::HEADER => '1']);

        $deal = $this->deal('Roof audit');
        self::assertSame(['location' => '/sales/deals/'.$deal->id()], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame([$this->company->id(), $this->stages['meeting']->id()], [$deal->clientPartyId(), $deal->stageId()]);

        $crawler = $this->client->request('GET', '/sales/deals/'.$deal->id());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Roof audit');
        self::assertSelectorTextContains('main', 'Petra Example');
        self::assertSelectorTextContains('[data-header-badge]', 'Meeting');
        self::assertCount(1, $crawler->filter('[data-deal-win]'));
        self::assertSame('/sales/deals/'.$deal->id().'/lose', $crawler->filter('[data-deal-lose]')->attr('href'));
        // Won is the primary action, at most one module's action stands beside it, and the rest wait in the "⋯" menu.
        self::assertLessThanOrEqual(1, $crawler->filter('[data-more-actions-buttons] a, [data-more-actions-buttons] button')->count());
        foreach (['[data-deal-lose]', '[data-deal-edit]', '[data-deal-follow-up]', '[data-deal-trash]'] as $action) {
            self::assertCount(1, $crawler->filter('[data-more-actions-menu] '.$action), $action);
        }
        // The details are in the side column, the stage among them while the deal is open.
        self::assertSelectorTextContains('[data-deal-layout] aside[data-deal-details]', 'Stage');
        self::assertSelectorTextContains('[data-deal-details]', 'Created');
        self::assertCount(1, $crawler->filter('[data-deal-main] [data-deal-lines]'));
        self::assertCount(1, $crawler->filter('[data-deal-details] [data-deal-links]'));

        // A refused field is shown in the dialog.
        $form = $this->client->request('GET', '/sales/deals/new?client='.$this->company->id())->filter('#deal-form')->form();
        $form['title'] = '';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#deal-form', 'Enter the title.');
    }

    public function testWinLoseWithAReasonAndReopen(): void
    {
        $deal = $this->newDeal('Roof audit');

        $this->client->request('POST', '/sales/deals/'.$deal->id().'/win', ['_token' => $this->token()]);
        self::assertResponseRedirects('/sales/deals/'.$deal->id());
        self::assertSame(DealStatus::Won, $this->deal('Roof audit')->status());
        $crawler = $this->client->request('GET', '/sales/deals/'.$deal->id());
        self::assertSelectorTextContains('main header', 'Won');
        // A won deal shows its status alone, not the stage it was closed in.
        self::assertCount(0, $crawler->filter('[data-header-badge]'));
        self::assertCount(0, $crawler->filter('[data-deal-stage]'));
        self::assertSelectorTextContains('[data-deal-details]', 'Won on');
        self::assertSelectorTextNotContains('[data-deal-details]', 'Stage');
        self::assertCount(0, $crawler->filter('[data-deal-lose]'));
        self::assertCount(0, $crawler->filter('[data-deal-win]'));
        $this->client->submit($crawler->filter('[data-deal-reopen]')->form());
        self::assertSame(DealStatus::Open, $this->deal('Roof audit')->status());

        // Lost asks for a reason from the list and a note, neither required.
        $crawler = $this->client->request('GET', '/sales/deals/'.$deal->id().'/lose', server: [self::HEADER => '1']);
        self::assertSame(['', $this->reasons['price']->id(), $this->reasons['timing']->id()], $crawler->filter('#lostReasonId option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['No reason', 'Price', 'Timing'], $crawler->filter('#lostReasonId option')->each(static fn (Crawler $option): string => trim($option->text())));
        $form = $crawler->filter('#deal-lose-form')->form();
        $this->choice($form['lostReasonId'])->select($this->reasons['timing']->id());
        $form['note'] = 'They will come back in spring.';
        $this->client->submit($form, [], [self::HEADER => '1']);
        self::assertResponseIsSuccessful();
        $lost = $this->deal('Roof audit');
        self::assertSame([DealStatus::Lost, $this->reasons['timing']->id(), 'They will come back in spring.'], [$lost->status(), $lost->lostReasonId(), $lost->lostNote()]);
        $crawler = $this->client->request('GET', '/sales/deals/'.$deal->id());
        self::assertSelectorTextContains('main', 'Timing');
        self::assertSelectorTextContains('main', 'They will come back in spring.');
        self::assertCount(0, $crawler->filter('[data-header-badge]'));
        self::assertSelectorTextContains('[data-deal-details]', 'Lost on');

        // Reopen clears the reason and the note.
        $this->client->submit($crawler->filter('[data-deal-reopen]')->form());
        $open = $this->deal('Roof audit');
        self::assertSame([DealStatus::Open, null, null], [$open->status(), $open->lostReasonId(), $open->lostNote()]);
    }

    public function testTheStageChangesFromTheHeader(): void
    {
        $deal = $this->newDeal('Roof audit');
        $crawler = $this->client->request('GET', '/sales/deals/'.$deal->id());
        $form = $crawler->filter('[data-deal-stage] form')->reduce(fn (Crawler $form): bool => $this->stages['proposal']->id() === $form->filter('input[name="stageId"]')->attr('value'))->form();
        $this->client->submit($form);
        self::assertSame($this->stages['proposal']->id(), $this->deal('Roof audit')->stageId());
    }

    public function testALineFromTheCatalogAndAFollowUpDeal(): void
    {
        $product = $this->product();
        $deal = $this->newDeal('Roof audit');

        $crawler = $this->client->request('GET', '/sales/deals/'.$deal->id().'/lines/new', server: [self::HEADER => '1']);
        self::assertCount(1, $crawler->filter('dialog#dialog-page [data-controller="deal-line"]'));
        $form = $crawler->filter('#deal-line-form')->form();
        $this->choice($form['productId'])->select($product->id());
        $form['quantity'] = '2';
        $this->client->submit($form, [], [self::HEADER => '1']);
        self::assertResponseIsSuccessful();
        // A typed line too, in euros.
        $form = $this->client->request('GET', '/sales/deals/'.$deal->id().'/lines/new')->filter('#deal-line-form')->form();
        $form['name'] = 'Travel';
        $form['unitPrice'] = '50';
        $this->choice($form['currency'])->select('EUR');
        $this->client->submit($form);
        self::assertResponseRedirects('/sales/deals/'.$deal->id());

        $lines = $this->lines($deal);
        self::assertSame(['Monthly maintenance', 'Travel'], array_map(static fn (DealLine $line): string => $line->name(), $lines));
        self::assertSame([$product->id(), '2.000', 150000], [$lines[0]->productId(), $lines[0]->quantity(), $lines[0]->unitPrice()?->minor()]);

        $crawler = $this->client->request('GET', '/sales/deals/'.$deal->id());
        self::assertSelectorTextContains('[data-deal-lines] table', 'Monthly maintenance');
        // One-off and monthly apart, per currency, in the footer of the lines table.
        $footer = $crawler->filter('[data-deal-lines] tfoot tr');
        self::assertCount(2, $footer);
        self::assertStringContainsString('Total one-off', $footer->eq(0)->text());
        self::assertStringContainsString("50.00\u{a0}EUR", $footer->eq(0)->text());
        self::assertStringContainsString('Total monthly', $footer->eq(1)->text());
        self::assertStringContainsString("3,000.00\u{a0}Kč", $footer->eq(1)->text());

        // The second line moves up.
        $up = $crawler->filter('[data-deal-lines] form')->reduce(static fn (Crawler $form): bool => 1 === $form->filter('input[name="direction"][value="up"]')->count())->first()->form();
        $this->client->submit($up);
        self::assertSame(['Travel', 'Monthly maintenance'], array_map(static fn (DealLine $line): string => $line->name(), $this->lines($deal)));

        // Edit changes a line in a dialog.
        $travel = $this->lines($deal)[0];
        $form = $this->client->request('GET', '/sales/deals/'.$deal->id().'/lines/'.$travel->id().'/edit', server: [self::HEADER => '1'])->filter('#deal-line-form')->form();
        $form['quantity'] = '3';
        $this->client->submit($form, [], [self::HEADER => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame('3.000', $this->lines($deal)[0]->quantity());

        // A follow-up deal for the same client, with the monthly line copied, follows from this one.
        $crawler = $this->client->request('GET', '/sales/deals/'.$deal->id().'/follow-up', server: [self::HEADER => '1']);
        $form = $crawler->filter('#deal-follow-up-form')->form();
        $form['title'] = 'Roof maintenance';
        $monthly = $this->lines($deal)[1]->id();
        foreach ($this->choices($form['lineIds']) as $box) {
            if ($monthly === $box->availableOptionValues()[0]) {
                $box->tick();
            }
        }
        $this->client->submit($form, [], [self::HEADER => '1']);
        $followUp = $this->deal('Roof maintenance');
        self::assertSame(['location' => '/sales/deals/'.$followUp->id()], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame(['Monthly maintenance'], array_map(static fn (DealLine $line): string => $line->name(), $this->lines($followUp)));
        $this->client->request('GET', '/sales/deals/'.$followUp->id());
        self::assertSelectorTextContains('[data-deal-links]', 'Follows from');
        self::assertSelectorTextContains('[data-deal-links]', 'Roof audit');
    }

    public function testALinkToAnotherDealOfTheClientIsAddedAndRemoved(): void
    {
        $audit = $this->newDeal('Roof audit');
        $repair = $this->newDeal('Gutter repair');

        $crawler = $this->client->request('GET', '/sales/deals/'.$repair->id().'/links/new', server: [self::HEADER => '1']);
        self::assertSame(['', $audit->id()], $crawler->filter('#toDealId option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        $form = $crawler->filter('#deal-link-form')->form();
        $this->choice($form['toDealId'])->select($audit->id());
        $this->client->submit($form, [], [self::HEADER => '1']);
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/sales/deals/'.$audit->id());
        self::assertSelectorTextContains('[data-deal-links]', 'Followed by');
        self::assertSelectorTextContains('[data-deal-links]', 'Gutter repair');
        // Linked already, so no deal is left to link.
        self::assertCount(0, $crawler->filter('[data-deal-link-new]'));
        $this->client->submit($crawler->filter('[data-deal-link-remove]')->form());
        self::assertCount(0, $this->em()->getRepository(DealLink::class)->findAll());
    }

    public function testTrashWithUndoAndRestore(): void
    {
        $deal = $this->newDeal('Roof audit');
        $this->client->request('POST', '/sales/deals/'.$deal->id().'/trash', ['_token' => $this->token()]);
        self::assertResponseRedirects('/sales/deals');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('[data-deal-trashed]', 'Roof audit');
        self::assertCount(0, $crawler->filter('[data-deal-stage]'));
        $this->client->request('GET', '/sales/deals?view=trashed');
        self::assertSelectorTextContains('main table', 'Roof audit');

        $this->client->submit($crawler->filter('[data-deal-trashed] form')->form());
        self::assertFalse($this->deal('Roof audit')->isTrashed());
    }

    public function testSettingsReorderTheStagesAndRefuseToArchiveOneWithOpenDeals(): void
    {
        $crawler = $this->client->request('GET', '/settings/sales');
        self::assertResponseIsSuccessful();
        self::assertSame(['Inquiry', 'Meeting', 'Proposal', 'Negotiation'], $this->stageNames($crawler));
        self::assertSelectorTextContains('[data-sales-reasons]', 'Price');

        // Meeting moves up, before Inquiry; the positions stay dense.
        $this->client->request('POST', '/settings/sales/stages/'.$this->stages['meeting']->id().'/move', ['_token' => $this->token(), 'direction' => 'up']);
        self::assertResponseRedirects('/settings/sales');
        $crawler = $this->client->followRedirect();
        self::assertSame(['Meeting', 'Inquiry', 'Proposal', 'Negotiation'], $this->stageNames($crawler));

        // A stage with an open deal is not archived, and the page says why.
        $this->newDeal('Roof audit', $this->stages['proposal']);
        $this->client->request('POST', '/settings/sales/stages/'.$this->stages['proposal']->id().'/archive', ['_token' => $this->token()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-sales-flash="error"]', 'The stage still holds open deals.');
        self::assertFalse($this->em()->find(Stage::class, $this->stages['proposal']->id())?->isArchived());

        // A new stage and a rename, each in a dialog.
        $form = $this->client->request('GET', '/settings/sales/stages/new', server: [self::HEADER => '1'])->filter('#sales-record-form')->form();
        $form['name'] = 'Site visit';
        $this->client->submit($form, [], [self::HEADER => '1']);
        self::assertResponseIsSuccessful();
        $form = $this->client->request('GET', '/settings/sales/stages/'.$this->stages['inquiry']->id().'/edit')->filter('#sales-record-form')->form();
        $form['name'] = 'First call';
        $this->client->submit($form);
        $crawler = $this->client->request('GET', '/settings/sales');
        self::assertSame(['Meeting', 'First call', 'Proposal', 'Negotiation', 'Site visit'], $this->stageNames($crawler));
    }

    public function testAGuestIsRefused(): void
    {
        $this->browser->seed(role: Role::Guest);
        $this->firm();
        $this->browser->signIn();

        foreach (['/sales/deals', '/sales/deals/new', '/sales/deals/client-persons', '/settings/sales'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }
    }

    private function newDeal(string $title, ?Stage $stage = null): Deal
    {
        $form = $this->client->request('GET', '/sales/deals/new?client='.$this->company->id())->filter('#deal-form')->form();
        $form['title'] = $title;
        if ($stage instanceof Stage) {
            $this->choice($form['stageId'])->select($stage->id());
        }
        $this->client->submit($form);
        self::assertResponseRedirects();

        return $this->deal($title);
    }

    private function product(): Product
    {
        $values = $this->client->request('GET', '/catalog/products/new')->filter('#product-form')->form()->getPhpValues();
        $this->client->request('POST', '/catalog/products/new', ['name' => 'Monthly maintenance', 'unit' => 'month', 'unitPrice' => '1500', 'billingKind' => 'recurring', 'interval' => '1', 'recurrenceUnit' => 'month'] + $values);
        self::assertResponseRedirects();
        $product = $this->em()->getRepository(Product::class)->findOneBy(['name' => 'Monthly maintenance']);
        self::assertInstanceOf(Product::class, $product);

        return $product;
    }

    private function deal(string $title): Deal
    {
        $this->em()->clear();
        $deal = $this->em()->getRepository(Deal::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Deal::class, $deal);

        return $deal;
    }

    /**
     * @return list<DealLine>
     */
    private function lines(Deal $deal): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(DealLine::class)->findBy(['dealId' => $deal->id()], ['position' => 'ASC']);
    }

    /**
     * @return list<string>
     */
    private function stageNames(Crawler $crawler): array
    {
        return $crawler->filter('[data-sales-stages] tbody tr')->each(static fn (Crawler $row): string => trim($row->filter('th, td')->first()->text()));
    }

    private function choice(mixed $field): ChoiceFormField
    {
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }

    /**
     * @return list<ChoiceFormField>
     */
    private function choices(mixed $fields): array
    {
        self::assertIsArray($fields);

        return array_values(array_map($this->choice(...), $fields));
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', '/sales/deals/new');

        return (string) $crawler->filter('#deal-form input[name="_token"]')->attr('value');
    }

    private function firm(): void
    {
        // A firm, so the instance is past its first-run setup.
        $this->em()->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->em()->flush();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
