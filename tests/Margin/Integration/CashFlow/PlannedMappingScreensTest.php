<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Integration\CashFlow;

use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\CashFlow\Contract\CashFlowSlots;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Margin\Domain\PlannedAllocation;
use Foresi\Margin\Integration\CashFlow\PlannedMappingPanel;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The "Split among clients" panel of a recurring planned cost and its dialog, as a staff user sees them and a
 * guest does not. Today is 10 December 2026.
 */
final class PlannedMappingScreensTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private Party $supplier;
    private Party $clientA;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-12-10 09:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testThePanelIsOnlyOnARecurringCost(): void
    {
        $this->start(Role::Owner);
        $cost = $this->planned();
        $oneOff = $this->planned(repeat: null);
        $income = $this->planned(direction: 'in', category: 'revenue');

        $page = $this->client->request('GET', '/cash-flow/planned-costs/'.$cost);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-planned-margin]'));
        self::assertSame('Split among clients', trim($page->filter('[data-planned-margin] h3')->text()));
        self::assertSame('/margin/planned-items/'.$cost.'/allocation', $page->filter('a[data-margin-planned-edit][data-dialog]')->attr('href'));
        self::assertCount(1, $page->filter('[data-margin-none]'));
        self::assertCount(0, $page->filter('[data-margin-versions]'));

        $this->client->request('GET', '/cash-flow/planned-costs/'.$oneOff);
        self::assertSelectorNotExists('[data-planned-margin]');
        $this->client->request('GET', '/cash-flow/planned-income/'.$income);
        self::assertSelectorNotExists('[data-planned-margin]');
    }

    public function testAMappingIsSavedFromAMonthAndShownWithItsOtherVersionsFolded(): void
    {
        $this->start(Role::Owner);
        $item = $this->planned();

        $dialog = $this->client->request('GET', '/margin/planned-items/'.$item.'/allocation');
        self::assertResponseIsSuccessful();
        self::assertSame('2026-12', $dialog->filter('#margin-from-month')->attr('value'), 'The month defaults to the current one.');
        self::assertCount(0, $dialog->filter('#margin-supplier'), 'The item has its supplier.');
        self::assertSame(['none', 'whole', 'percent', 'fixed'], $dialog->filter('#margin-kind option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        $this->client->submit($dialog->filter('#margin-planned-form')->form([
            'kind' => 'percent',
            'shares[0][type]' => 'client', 'shares[0][contactId]' => $this->clientA->id(), 'shares[0][query]' => 'Client A s.r.o.', 'shares[0][percent]' => '40',
            'shares[1][type]' => 'own', 'shares[1][percent]' => '60',
        ]));

        self::assertResponseRedirects('/cash-flow/planned-costs/'.$item, 303);
        $page = $this->client->followRedirect();
        $current = $page->filter('[data-margin-version][data-margin-placed="current"]');
        self::assertCount(1, $current);
        self::assertSame('2026-12', trim($current->filter('[data-margin-month]')->text()));
        self::assertSame('percent', $current->filter('[data-margin-kind]')->attr('data-margin-kind'));
        self::assertSame(['Client A s.r.o.', 'Own use'], $current->filter('[data-margin-term] span:first-child')->each(static fn (Crawler $name): string => trim($name->text())));
        self::assertStringContainsString('40', $current->filter('[data-margin-term]')->first()->text());
        self::assertCount(0, $page->filter('[data-margin-versions]'));

        // An earlier month and a later one, which ends the mapping.
        $this->save($item, ['kind' => 'whole', 'fromMonth' => '2026-10', 'shares[0][type]' => 'own']);
        $this->save($item, ['kind' => 'none', 'fromMonth' => '2027-02']);
        $page = $this->client->request('GET', '/cash-flow/planned-costs/'.$item);

        self::assertCount(1, $page->filter('[data-margin-placed="current"]'));
        $folded = $page->filter('[data-margin-versions]');
        self::assertCount(1, $folded);
        self::assertSame(['2027-02', '2026-10'], $folded->filter('[data-margin-version]')->each(static fn (Crawler $version): string => (string) $version->attr('data-margin-version')));
        self::assertSame(['later', 'earlier'], $folded->filter('[data-margin-version]')->each(static fn (Crawler $version): string => (string) $version->attr('data-margin-placed')));
        self::assertCount(1, $folded->filter('[data-margin-ended]'));
        self::assertSame(3, $this->stored());

        // The dialog starts from the mapping in force and saving the same month replaces its version.
        $again = $this->client->request('GET', '/margin/planned-items/'.$item.'/allocation');
        self::assertSame('percent', $again->filter('#margin-kind option[selected]')->attr('value'));
        self::assertSame('40', $again->filter('#margin-percent-0')->attr('value'));
        $this->save($item, ['kind' => 'whole', 'fromMonth' => '2026-12', 'shares[0][type]' => 'own']);
        self::assertSame(3, $this->stored());
    }

    public function testAnItemWithoutASupplierIsGivenOneInTheSameRequest(): void
    {
        $this->start(Role::Owner);
        $item = $this->planned(counterparty: false);

        $dialog = $this->client->request('GET', '/margin/planned-items/'.$item.'/allocation');
        self::assertCount(1, $dialog->filter('#margin-supplier'));

        $page = $this->client->submit($dialog->filter('#margin-planned-form')->form(['kind' => 'whole', 'shares[0][type]' => 'own']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Pick the supplier of the item from the list.', $page->filter('[data-margin-error]')->text());
        self::assertNull($this->item($item)->counterparty());
        self::assertSame(0, $this->stored());

        $this->client->submit($page->filter('#margin-planned-form')->form(['supplierId' => $this->supplier->id(), 'supplierQuery' => 'Cloud Host s.r.o.']));

        self::assertResponseRedirects('/cash-flow/planned-costs/'.$item, 303);
        self::assertSame($this->supplier->id(), $this->item($item)->counterparty()?->id());
        self::assertSame(1, $this->stored());
    }

    public function testASupplierSavedBeforeARefusedMappingStaysAndIsNotAskedAgain(): void
    {
        $this->start(Role::Owner);
        $item = $this->planned(counterparty: false);

        $dialog = $this->client->request('GET', '/margin/planned-items/'.$item.'/allocation');
        $page = $this->client->submit($dialog->filter('#margin-planned-form')->form([
            'supplierId' => $this->supplier->id(), 'supplierQuery' => 'Cloud Host s.r.o.',
            'kind' => 'percent',
            'shares[0][type]' => 'own', 'shares[0][percent]' => '60',
            'shares[1][type]' => 'client', 'shares[1][contactId]' => $this->clientA->id(), 'shares[1][percent]' => '30',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('adding up to exactly 100', $page->filter('[data-margin-error]')->text());
        self::assertCount(1, $page->filter('[data-margin-supplier-saved]'));
        self::assertCount(0, $page->filter('#margin-supplier'), 'The supplier is saved, so it is not asked for again.');
        self::assertSame('60', $page->filter('#margin-percent-0')->attr('value'), 'What was typed stays.');
        self::assertSame($this->supplier->id(), $this->item($item)->counterparty()?->id());
        self::assertSame(0, $this->stored());
    }

    public function testNoMappingFromAMonthNeedsNoSupplier(): void
    {
        $this->start(Role::Owner);
        $item = $this->planned(counterparty: false);

        $this->save($item, ['kind' => 'none', 'fromMonth' => '2026-12']);

        $page = $this->client->request('GET', '/cash-flow/planned-costs/'.$item);
        self::assertCount(1, $page->filter('[data-margin-ended]'));
        self::assertNull($this->item($item)->counterparty());
        self::assertSame(1, $this->stored());
    }

    public function testWhatIsWrongIsToldInTheFormAndNothingIsSaved(): void
    {
        $this->start(Role::Owner);
        $item = $this->planned();
        $dialog = $this->client->request('GET', '/margin/planned-items/'.$item.'/allocation');
        $form = $dialog->filter('#margin-planned-form')->form();

        $page = $this->client->submit($form, ['kind' => 'whole', 'fromMonth' => 'december', 'shares[0][type]' => 'own']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A month is written as YYYY-MM.', $page->filter('[data-margin-error]')->text());

        $page = $this->client->submit($page->filter('#margin-planned-form')->form(), ['kind' => 'whole', 'fromMonth' => '2026-12', 'shares[0][type]' => 'client', 'shares[0][percent]' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Pick the client of a row from the list.', $page->filter('[data-margin-error]')->text());

        $values = $page->filter('#margin-planned-form')->form(['shares[0][type]' => 'own'])->getPhpValues();
        $page = $this->client->request('POST', '/margin/planned-items/'.$item.'/allocation', ['kind' => 'sideways'] + $values);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The kind is whole, percent or fixed.', $page->filter('[data-margin-error]')->text());

        $page = $this->client->submit($page->filter('#margin-planned-form')->form(), ['kind' => 'fixed', 'shares[0][type]' => 'own', 'shares[0][amount]' => '-5']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('An amount is a number in CZK with at most two decimals.', $page->filter('[data-margin-error]')->text());
        self::assertSame(0, $this->stored());
    }

    public function testAnotherKindOfItemHasNoDialog(): void
    {
        $this->start(Role::Owner);

        foreach ([$this->planned(repeat: null), $this->planned(direction: 'in', category: 'revenue'), '0197a1de-0000-7000-8000-0000000000ff'] as $id) {
            $this->client->request('GET', '/margin/planned-items/'.$id.'/allocation');
            self::assertResponseStatusCodeSame(404, $id);
        }
    }

    public function testTheMonthOfTheDialogIsTheMonthOfTheInstanceInTheLastHourOfAMonth(): void
    {
        $this->start(Role::Owner);
        $item = $this->planned();
        // 23:30 UTC on 30 November is half past midnight on 1 December in Prague.
        Clock::set(new MockClock('2026-11-30 23:30:00', 'UTC'));

        $dialog = $this->client->request('GET', '/margin/planned-items/'.$item.'/allocation');

        self::assertSame('2026-12', $dialog->filter('#margin-from-month')->attr('value'));
    }

    public function testThePanelAppearsOnlyInThePlannedItemSections(): void
    {
        $this->start(Role::Owner);
        $item = $this->planned();
        $panel = self::getContainer()->get(PlannedMappingPanel::class);

        self::assertCount(1, [...$panel->items(CashFlowSlots::PLANNED_ITEM_SECTIONS, ['plannedItemId' => $item])]);
        self::assertSame([], [...$panel->items('other.slot', ['plannedItemId' => $item])]);
        self::assertSame([], [...$panel->items(CashFlowSlots::PLANNED_ITEM_SECTIONS, [])]);
        self::assertSame([], [...$panel->items(CashFlowSlots::PLANNED_ITEM_SECTIONS, ['plannedItemId' => 5])]);
        self::assertSame([], [...$panel->items(CashFlowSlots::PLANNED_ITEM_SECTIONS, ['plannedItemId' => '0197a1de-0000-7000-8000-0000000000ff'])]);
    }

    public function testAGuestHasNoPanelAndCannotDecide(): void
    {
        $this->start(Role::Owner);
        $item = $this->planned();
        $membership = $this->browser->em()->getRepository(Membership::class)->findOneBy([]);
        self::assertInstanceOf(Membership::class, $membership);
        $membership->changeRole(Role::Guest, $this->browser->em()->find(Party::class, $this->clientA->id()));
        $this->browser->em()->flush();

        $this->client->request('GET', '/margin/planned-items/'.$item.'/allocation');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/margin/planned-items/'.$item.'/allocation', ['kind' => 'whole', 'fromMonth' => '2026-12', 'shares' => [['type' => 'own']], '_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->stored());
        $this->client->request('GET', '/margin/contacts?q=Client');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function save(string $item, array $values): void
    {
        $dialog = $this->client->request('GET', '/margin/planned-items/'.$item.'/allocation');
        // The dialog starts from the mapping in force, whose second recipient is cleared unless the test sets it.
        $this->client->submit($dialog->filter('#margin-planned-form')->form($values + ['shares[1][type]' => '', 'shares[1][percent]' => '']));
        self::assertResponseRedirects('/cash-flow/planned-costs/'.$item, 303);
    }

    private function start(Role $role): void
    {
        $this->browser->seed('en', $role);
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        FirmAccounts::add($em, 'CZ6508000000192000145399');
        $this->supplier = new Party(PartyKind::Company, 'Cloud Host s.r.o.', new RegistrationNumber('CZ', '11223344'), null, '');
        $this->clientA = new Party(PartyKind::Company, 'Client A s.r.o.', new RegistrationNumber('CZ', '27082440'), null, '');
        $em->persist($this->supplier);
        $em->persist($this->clientA);
        $em->flush();
        $this->browser->signIn();
    }

    private function planned(string $direction = 'out', string $category = 'software', ?string $repeat = 'month', bool $counterparty = true): string
    {
        $item = self::getContainer()->get(PlannedItemWrites::class)->create(new CreatePlannedItemInput(
            direction: $direction,
            name: 'Cloud Host',
            category: $category,
            amount: '12100.00',
            on: '2026-12-05',
            repeat: $repeat,
            counterpartyId: $counterparty ? $this->supplier->id() : null,
        ));
        $this->browser->em()->flush();

        return $item->id();
    }

    private function item(string $id): PlannedItem
    {
        $this->browser->em()->clear();

        return $this->browser->em()->find(PlannedItem::class, $id) ?? throw new \LogicException('The planned item is gone.');
    }

    private function stored(): int
    {
        return $this->browser->em()->getRepository(PlannedAllocation::class)->count([]);
    }
}
