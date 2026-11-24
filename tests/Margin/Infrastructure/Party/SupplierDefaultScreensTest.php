<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Infrastructure\Party;

use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Margin\Domain\SupplierDefault;
use Foresi\Margin\Infrastructure\Party\MarginContactTab;
use Foresi\Party\Contract\ContactTabs;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Instance\Support\InstanceZone;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Margin tab of a contact, where the default recipient of a supplier's costs is read and set from a month on,
 * as a staff user sees it and a guest does not. Today is 10 December 2026.
 */
final class SupplierDefaultScreensTest extends WebTestCase
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
        // The instance counts months in Prague, also with only the modules Margin requires.
        InstanceZone::set(self::getContainer());
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheContactHasAMarginTabThatLoadsItsBodyInTheContact(): void
    {
        $this->start(Role::Owner);
        $id = $this->supplier->id();

        $page = $this->client->request('GET', '/contacts/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame('/contacts/'.$id.'?tab=margin', $page->filter('main nav[aria-label="Contact sections"] a:contains("Margin")')->attr('href'));

        $page = $this->client->request('GET', '/contacts/'.$id.'?tab=margin');
        self::assertSame('/margin/suppliers/'.$id.'/default', $page->filter('turbo-frame#'.ContactTabs::FRAME)->attr('src'));

        $frame = $this->client->request('GET', '/margin/suppliers/'.$id.'/default');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $frame->filter('turbo-frame#'.ContactTabs::FRAME));
        self::assertStringContainsString('Default recipient of the costs of this supplier', $frame->filter('[data-supplier-margin] h2')->text());
        self::assertSame('/margin/suppliers/'.$id.'/default/edit', $frame->filter('a[data-margin-default-edit][data-dialog]')->attr('href'));
        self::assertCount(1, $frame->filter('[data-margin-none]'));
        self::assertCount(0, $frame->filter('[data-margin-versions]'));
    }

    public function testADefaultIsSetFromAMonthAndTheOtherVersionsAreFolded(): void
    {
        $this->start(Role::Owner);
        $id = $this->supplier->id();

        $dialog = $this->client->request('GET', '/margin/suppliers/'.$id.'/default/edit');
        self::assertResponseIsSuccessful();
        self::assertSame('2026-12', $dialog->filter('#margin-from-month')->attr('value'), 'The month defaults to the current one.');
        self::assertCount(0, $dialog->filter('#margin-type option[selected][value!=""]'), 'Nobody until a default is set.');
        $this->client->submit($dialog->filter('#margin-default-form')->form(['type' => 'client', 'contactId' => $this->clientA->id(), 'query' => 'Client A s.r.o.']));

        self::assertResponseRedirects('/contacts/'.$id.'?tab=margin', 303);
        $frame = $this->client->request('GET', '/margin/suppliers/'.$id.'/default');
        $current = $frame->filter('[data-margin-placed="current"]');
        self::assertSame('2026-12', trim($current->filter('[data-margin-month]')->text()));
        self::assertSame('Client A s.r.o.', trim($current->filter('[data-margin-recipient]')->text()));

        $this->save($id, ['type' => 'own', 'fromMonth' => '2026-10']);
        $this->save($id, ['type' => '', 'fromMonth' => '2027-03']);
        $frame = $this->client->request('GET', '/margin/suppliers/'.$id.'/default');

        self::assertCount(1, $frame->filter('[data-margin-placed="current"]'));
        $folded = $frame->filter('[data-margin-versions]');
        self::assertCount(1, $folded);
        self::assertSame(['2027-03', '2026-10'], $folded->filter('[data-margin-version]')->each(static fn (Crawler $version): string => (string) $version->attr('data-margin-version')));
        self::assertSame(['later', 'earlier'], $folded->filter('[data-margin-version]')->each(static fn (Crawler $version): string => (string) $version->attr('data-margin-placed')));
        self::assertSame(['Nobody, no default from this month on', 'Own consumption'], $folded->filter('[data-margin-recipient]')->each(static fn (Crawler $recipient): string => trim($recipient->text())));
        self::assertSame(3, $this->stored());

        // The dialog starts from the default in force, and the same month replaces its version.
        $again = $this->client->request('GET', '/margin/suppliers/'.$id.'/default/edit');
        self::assertSame('client', $again->filter('#margin-type option[selected]')->attr('value'));
        self::assertSame($this->clientA->id(), $again->filter('input[name="contactId"]')->attr('value'));
        $this->save($id, ['type' => 'own', 'fromMonth' => '2026-12']);
        self::assertSame(3, $this->stored());
    }

    public function testWhatIsWrongIsToldInTheFormAndNothingIsSaved(): void
    {
        $this->start(Role::Owner);
        $id = $this->supplier->id();
        $dialog = $this->client->request('GET', '/margin/suppliers/'.$id.'/default/edit');

        $page = $this->client->submit($dialog->filter('#margin-default-form')->form(['type' => 'client', 'contactId' => '', 'query' => 'Cli']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Pick the client of a row from the list.', $page->filter('[data-margin-error]')->text());
        self::assertSame('Cli', $page->filter('#margin-contact-text, input[name="query"]')->first()->attr('value'), 'What was typed stays.');

        $page = $this->client->submit($page->filter('#margin-default-form')->form(['type' => 'own', 'fromMonth' => '12/2026']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A month is written as YYYY-MM.', $page->filter('[data-margin-error]')->text());

        $page = $this->client->submit($page->filter('#margin-default-form')->form(['type' => 'client', 'contactId' => '0197a1de-0000-7000-8000-0000000000ff', 'fromMonth' => '2026-12']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('not an active contact', $page->filter('[data-margin-error]')->text());
        self::assertSame(0, $this->stored());
    }

    public function testTheMonthOfTheDialogIsTheMonthOfTheInstanceInTheLastHourOfAMonth(): void
    {
        $this->start(Role::Owner);
        // 23:30 UTC on 30 November is half past midnight on 1 December in Prague.
        Clock::set(new MockClock('2026-11-30 23:30:00', 'UTC'));

        $dialog = $this->client->request('GET', '/margin/suppliers/'.$this->supplier->id().'/default/edit');

        self::assertSame('2026-12', $dialog->filter('#margin-from-month')->attr('value'));
    }

    public function testTheTabIsOnlyOfferedToStaff(): void
    {
        $this->start(Role::Owner);
        $id = $this->supplier->id();
        $tab = self::getContainer()->get(MarginContactTab::class);

        $items = [...$tab->items(ContactTabs::SLOT, ['contactId' => $id])];
        self::assertCount(1, $items);
        self::assertSame('margin', $items[0]->data['key']);
        self::assertSame('/margin/suppliers/'.$id.'/default', $items[0]->data['href']);
        self::assertSame([], [...$tab->items('other.slot', ['contactId' => $id])]);
        self::assertSame([], [...$tab->items(ContactTabs::SLOT, [])]);
    }

    public function testAGuestHasNoTabAndCannotDecide(): void
    {
        $this->start(Role::Owner);
        $id = $this->supplier->id();
        $membership = $this->browser->em()->getRepository(Membership::class)->findOneBy([]);
        self::assertInstanceOf(Membership::class, $membership);
        $membership->changeRole(Role::Guest, $this->browser->em()->find(Party::class, $this->clientA->id()));
        $this->browser->em()->flush();

        self::assertSame([], [...self::getContainer()->get(MarginContactTab::class)->items(ContactTabs::SLOT, ['contactId' => $id])]);
        foreach (['/margin/suppliers/'.$id.'/default', '/margin/suppliers/'.$id.'/default/edit'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->client->request('POST', '/margin/suppliers/'.$id.'/default/edit', ['type' => 'own', 'fromMonth' => '2026-12', '_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->stored());
    }

    public function testAContactThatDoesNotExistHasNoTab(): void
    {
        $this->start(Role::Owner);

        $this->client->request('GET', '/margin/suppliers/0197a1de-0000-7000-8000-0000000000ff/default');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/margin/suppliers/0197a1de-0000-7000-8000-0000000000ff/default/edit');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function save(string $id, array $values): void
    {
        $dialog = $this->client->request('GET', '/margin/suppliers/'.$id.'/default/edit');
        $this->client->submit($dialog->filter('#margin-default-form')->form($values));
        self::assertResponseRedirects('/contacts/'.$id.'?tab=margin', 303);
    }

    private function start(Role $role): void
    {
        $this->browser->seed('en', $role);
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->supplier = new Party(PartyKind::Company, 'Cloud Host s.r.o.', new RegistrationNumber('CZ', '11223344'), null, '');
        $this->clientA = new Party(PartyKind::Company, 'Client A s.r.o.', new RegistrationNumber('CZ', '27082440'), null, '');
        $em->persist($this->supplier);
        $em->persist($this->clientA);
        $em->flush();
        $this->browser->signIn();
    }

    private function stored(): int
    {
        return $this->browser->em()->getRepository(SupplierDefault::class)->count([]);
    }
}
