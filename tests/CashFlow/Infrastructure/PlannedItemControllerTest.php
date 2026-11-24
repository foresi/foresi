<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Infrastructure;

use Foresi\CashFlow\Contract\PlannedVatMode;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Party\Domain\VatStatusPeriod;
use Foresi\Tests\CashFlow\Support\ExamplePlannedItemSection;
use Foresi\Tests\ExchangeRate\Support\FakeRateSource;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Planned income and Planned costs: a list each under Money, the "+ New"
 * dialog that takes the page's direction, and the item's sheet, which edits,
 * leaves out and includes, skips an occurrence, ends and deletes it.
 */
final class PlannedItemControllerTest extends WebTestCase
{
    private const string HEADER = 'HTTP_X_FORESI_DIALOG';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-19 10:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $rates = new FakeRateSource();
        $rates->publish('2026-11-18', '24.500', '21.000');
        self::getContainer()->set(RateSource::class, $rates);
        $browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $browser->seed();
        $browser->signIn();
        $em = $browser->em();
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $em->persist($firm);
        FirmAccounts::add($em, 'CZ6508000000192000145399');
        $em->flush();
    }

    protected function tearDown(): void
    {
        ExamplePlannedItemSection::$enabled = false;
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testBothPagesAreUnderMoneyAndStartEmpty(): void
    {
        $crawler = $this->client->request('GET', '/cash-flow/planned-costs');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Planned costs');
        self::assertSame('/cash-flow/planned-costs/new', $crawler->filter('header [data-planned-new][data-dialog]')->attr('href'));
        self::assertSelectorTextContains('[data-planned-items="out"]', 'No planned costs yet');
        self::assertCount(1, $crawler->filter('nav a[href="/cash-flow/planned-income"]'));
        self::assertCount(1, $crawler->filter('nav a[href="/cash-flow/planned-costs"][aria-current="page"]'));

        $crawler = $this->client->request('GET', '/cash-flow/planned-income');
        self::assertSelectorTextContains('h1', 'Planned income');
        self::assertSame('/cash-flow/planned-income/new', $crawler->filter('header [data-planned-new]')->attr('href'));
        // Without JavaScript the dialog is a page of its own.
        $this->client->request('GET', '/cash-flow/planned-income/new');
        self::assertResponseIsSuccessful();
    }

    public function testAnotherModulesSectionIsShownOnThePageOfAPlannedItemAndNothingWithoutIt(): void
    {
        $values = $this->dialogForm('/cash-flow/planned-costs/new');
        $this->dialogPost('/cash-flow/planned-costs/new', ['name' => 'Cloud Host', 'category' => 'software', 'amount' => '100', 'on' => '2026-12-01', 'repeat' => 'month', 'every' => '1', 'end' => 'never'] + $values);
        $id = $this->item()->id();

        $crawler = $this->client->request('GET', '/cash-flow/planned-costs/'.$id);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-example-planned-item-section]'));

        ExamplePlannedItemSection::$enabled = true;
        $crawler = $this->client->request('GET', '/cash-flow/planned-costs/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame('Example section', $crawler->filter('[data-example-planned-item-section="'.$id.'"]')->text());
    }

    public function testTheNewDialogTakesTheDirectionOfItsPage(): void
    {
        $values = $this->dialogForm('/cash-flow/planned-costs/new');
        self::assertArrayNotHasKey('direction', $values);
        self::assertSame('2026-11-19', $values['on']);
        self::assertSame('1', $values['included']);

        $this->dialogPost('/cash-flow/planned-costs/new', ['name' => 'Office rent', 'category' => 'rent', 'amount' => '25 000,50', 'on' => '2026-12-01', 'repeat' => 'month', 'every' => '1', 'end' => 'never'] + $values);
        self::assertSame('{"location":"\/cash-flow\/planned-costs"}', $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', '/cash-flow/planned-costs');
        $row = $crawler->filter('[data-planned-items] tbody tr');
        self::assertCount(1, $row);
        self::assertStringContainsString('Office rent', $row->text());
        self::assertStringContainsString('Every month', $row->text());
        self::assertStringContainsString('Dec 1', $row->text());
        self::assertStringContainsString('25,000.50', $row->text());
        self::assertStringContainsString('Included', $row->text());
        self::assertSelectorTextContains('[data-planned-items="out"]', 'Planned items stay outside the books');

        $income = $this->client->request('GET', '/cash-flow/planned-income');
        self::assertCount(0, $income->filter('[data-planned-items] tbody tr'));
        self::assertSame('out', $this->item()->direction()->value);
        self::assertSame(2500050, $this->item()->amount()->minor());
    }

    public function testARefusedFieldStaysInTheDialog(): void
    {
        $values = $this->dialogForm('/cash-flow/planned-income/new');

        $crawler = $this->dialogPost('/cash-flow/planned-income/new', ['name' => '', 'amount' => '100'] + $values);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter a name of up to 120 characters.', $crawler->filter('#name')->ancestors()->first()->text());
        $crawler = $this->dialogPost('/cash-flow/planned-income/new', ['name' => 'Grant', 'amount' => ''] + $values);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter the amount.', $crawler->text());
    }

    public function testTheSheetLeavesOutSkipsEndsAndDeletes(): void
    {
        $values = $this->dialogForm('/cash-flow/planned-costs/new');
        $this->dialogPost('/cash-flow/planned-costs/new', ['name' => 'Software', 'category' => 'software', 'amount' => '1200', 'on' => '2026-11-25', 'repeat' => 'month', 'every' => '1', 'end' => 'never'] + $values);
        $id = $this->item()->id();

        $crawler = $this->client->request('GET', '/cash-flow/planned-costs/'.$id, server: [self::HEADER => DialogRequest::SHEET]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('dialog#sheet-page'));
        $occurrences = $crawler->filter('[data-occurrence]');
        self::assertCount(6, $occurrences);
        $page = $this->client->request('GET', '/cash-flow/planned-costs/'.$id);
        self::assertCount(1, $page->filter('nav a[href="/cash-flow/planned-costs"][aria-current="page"]'));
        self::assertSame('2026-11-25', $occurrences->eq(0)->attr('data-occurrence'));
        $token = $crawler->filter('[data-planned-include] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/cash-flow/planned/'.$id.'/include', ['_token' => $token, 'included' => '0']);
        self::assertResponseRedirects('/cash-flow/planned-costs/'.$id);
        self::assertFalse($this->item()->isIncluded());
        $crawler = $this->client->request('GET', '/cash-flow/planned-costs/'.$id);
        self::assertSelectorExists('[data-planned-left-out]');
        self::assertStringContainsString('Include in the forecast', $crawler->filter('[data-planned-include]')->text());
        $list = $this->client->request('GET', '/cash-flow/planned-costs');
        self::assertStringContainsString('Left out', $list->filter('[data-planned-items] tbody tr')->text());

        $this->client->request('POST', '/cash-flow/planned/'.$id.'/skip', ['_token' => $token, 'on' => '2026-11-25']);
        self::assertResponseRedirects('/cash-flow/planned-costs/'.$id);
        $crawler = $this->client->request('GET', '/cash-flow/planned-costs/'.$id);
        self::assertCount(1, $crawler->filter('[data-occurrence="2026-11-25"][data-skipped]'));
        $list = $this->client->request('GET', '/cash-flow/planned-costs');
        self::assertStringContainsString('Dec 25', $list->filter('[data-planned-items] tbody tr')->text());

        $end = $this->dialogForm('/cash-flow/planned-costs/'.$id.'/end', '#planned-end-form');
        $this->dialogPost('/cash-flow/planned-costs/'.$id.'/end', ['on' => '2026-10-01'] + $end);
        self::assertResponseStatusCodeSame(422);
        $this->dialogPost('/cash-flow/planned-costs/'.$id.'/end', ['on' => '2027-01-31'] + $end);
        self::assertResponseIsSuccessful();
        $crawler = $this->client->request('GET', '/cash-flow/planned-costs/'.$id);
        self::assertCount(3, $crawler->filter('[data-occurrence]'));
        self::assertStringContainsString('On Jan 31, 2027', $crawler->filter('dl')->text());

        // An item's pages are under the page of its direction only.
        $this->client->request('GET', '/cash-flow/planned-income/'.$id);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/cash-flow/planned/'.$id.'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/cash-flow/planned-costs');
        self::assertNull($this->itemOrNull());
    }

    public function testEditKeepsWhatItIsNotAskedAbout(): void
    {
        $values = $this->dialogForm('/cash-flow/planned-income/new');
        $this->dialogPost('/cash-flow/planned-income/new', ['name' => 'Retainer', 'amount' => '30000', 'on' => '2026-12-05', 'repeat' => 'month', 'every' => '1', 'end' => 'after_count', 'endsAfter' => '6', 'matchVariableSymbol' => '2026001'] + $values);
        $id = $this->item()->id();

        $edit = $this->dialogForm('/cash-flow/planned-income/'.$id.'/edit');
        self::assertSame('Retainer', $edit['name']);
        self::assertSame('30000.00', $edit['amount']);
        self::assertSame('after_count', $edit['end']);
        self::assertSame('6', $edit['endsAfter']);
        self::assertSame('2026001', $edit['matchVariableSymbol']);

        $this->dialogPost('/cash-flow/planned-income/'.$id.'/edit', ['amount' => '32000', 'repeat' => ''] + $edit);
        self::assertSame('{"location":"\/cash-flow\/planned-income\/'.$id.'"}', $this->client->getResponse()->getContent());
        $item = $this->item();
        self::assertSame(3200000, $item->amount()->minor());
        self::assertFalse($item->isRecurring());
        self::assertSame('2026001', $item->matchRule()->variableSymbol());
        // A one-off item cannot be ended.
        $this->client->request('GET', '/cash-flow/planned-income/'.$id.'/end');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheFormStatesTheVatAndTheListMarksAPayersItemWithoutIt(): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist(new VatStatusPeriod(VatStatus::NotRegistered, null, null));
        $em->persist(new VatStatusPeriod(VatStatus::Registered, VatPeriodLength::Month, new \DateTimeImmutable('2026-11-12')));
        $em->flush();
        $values = $this->dialogForm('/cash-flow/planned-costs/new');
        self::assertSame(['none', ''], [$values['vatMode'], $values['vatRate']]);

        $crawler = $this->dialogPost('/cash-flow/planned-costs/new', ['name' => 'Office rent', 'category' => 'rent', 'amount' => '10000', 'on' => '2026-12-01', 'vatMode' => 'excluded', 'vatRate' => ''] + $values);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter the VAT rate, such as 21.', $crawler->filter('#vatRate')->ancestors()->first()->text());

        $this->dialogPost('/cash-flow/planned-costs/new', ['name' => 'Office rent', 'category' => 'rent', 'amount' => '10000', 'on' => '2026-12-01', 'vatMode' => 'excluded', 'vatRate' => '12,5'] + $values);
        self::assertSame('{"location":"\/cash-flow\/planned-costs"}', $this->client->getResponse()->getContent());
        $item = $this->item();
        self::assertSame(['excluded', '12.5'], [$item->vatMode()->value, $item->vatRate()]);
        $sheet = $this->client->request('GET', '/cash-flow/planned-costs/'.$item->id());
        self::assertMatchesRegularExpression('/VAT 10,000\.00\s+Kč \+ 12\.5 % VAT 1,250\.00\s+Kč on top/u', $sheet->filter('dl')->text());
        $list = $this->client->request('GET', '/cash-flow/planned-costs');
        self::assertStringNotContainsString('VAT not stated', $list->filter('[data-planned-items] tbody tr')->text());

        $edit = $this->dialogForm('/cash-flow/planned-costs/'.$item->id().'/edit');
        self::assertSame(['excluded', '12.5'], [$edit['vatMode'], $edit['vatRate']]);
        $this->dialogPost('/cash-flow/planned-costs/'.$item->id().'/edit', ['vatMode' => 'none'] + $edit);
        self::assertSame([PlannedVatMode::None, null], [$this->item()->vatMode(), $this->item()->vatRate()], 'Without a mode the rate field is ignored.');
        $list = $this->client->request('GET', '/cash-flow/planned-costs');
        self::assertStringContainsString('VAT not stated', $list->filter('[data-planned-items] tbody tr')->text());
        $sheet = $this->client->request('GET', '/cash-flow/planned-costs/'.$item->id());
        self::assertStringContainsString('No VAT stated', $sheet->filter('dl')->text());
    }

    public function testACostStatesSelfAssessedVatAndIncomeDoesNotOfferIt(): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist(new VatStatusPeriod(VatStatus::NotRegistered, null, null));
        $em->persist(new VatStatusPeriod(VatStatus::Registered, VatPeriodLength::Month, new \DateTimeImmutable('2026-11-12')));
        $em->flush();
        $income = $this->client->request('GET', '/cash-flow/planned-income/new');
        self::assertSame(['none', 'included', 'excluded'], $income->filter('#vatMode option')->each(static fn ($option): string => (string) $option->attr('value')));
        $costs = $this->client->request('GET', '/cash-flow/planned-costs/new');
        self::assertSame('Self-assessed VAT', $costs->filter('#vatMode option[value="reverse_charge"]')->text());

        $values = $this->dialogForm('/cash-flow/planned-costs/new');
        $this->dialogPost('/cash-flow/planned-costs/new', ['name' => 'Cloud hosting', 'category' => 'software', 'amount' => '1000', 'on' => '2026-12-01', 'vatMode' => 'reverse_charge', 'vatRate' => '21'] + $values);
        self::assertSame('{"location":"\/cash-flow\/planned-costs"}', $this->client->getResponse()->getContent());
        $item = $this->item();
        self::assertSame([PlannedVatMode::ReverseCharge, '21'], [$item->vatMode(), $item->vatRate()]);
        $sheet = $this->client->request('GET', '/cash-flow/planned-costs/'.$item->id());
        self::assertMatchesRegularExpression('/1,000\.00\s+Kč, 21 % VAT 210\.00\s+Kč self-assessed/u', $sheet->filter('dl')->text());
        $list = $this->client->request('GET', '/cash-flow/planned-costs');
        self::assertStringNotContainsString('VAT not stated', $list->filter('[data-planned-items] tbody tr')->text());
    }

    public function testTheCashFlowPageLinksToBothPages(): void
    {
        $values = $this->dialogForm('/cash-flow/balance', '#cash-flow-balance-form');
        $this->dialogPost('/cash-flow/balance', ['amount' => '10000'] + $values);

        $crawler = $this->client->request('GET', '/cash-flow');

        $links = $crawler->filter('[data-cash-flow-planned-links] a');
        self::assertSame(['/cash-flow/planned-income', '/cash-flow/planned-costs'], $links->each(static fn (Crawler $link): ?string => $link->attr('href')));
    }

    public function testTheCounterpartyFieldOffersTheMatchingContactsInNameOrder(): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $second = new Party(PartyKind::Person, 'Landlord Second', null, null, '');
        $first = new Party(PartyKind::Company, 'Landlord First s.r.o.', new RegistrationNumber('CZ', '11223344'), null, '');
        $archived = new Party(PartyKind::Company, 'Landlord Archived s.r.o.', new RegistrationNumber('CZ', '27074358'), null, '');
        $archived->archive(new \DateTimeImmutable('2026-11-01'));
        $other = new Party(PartyKind::Company, 'Other Example s.r.o.', new RegistrationNumber('CZ', '25596641'), null, '');
        foreach ([$second, $first, $archived, $other] as $party) {
            $em->persist($party);
        }
        $em->persist(new PartyEmail($first, 'first@example.com', EmailPurpose::General));
        $em->flush();

        $this->client->request('GET', '/cash-flow/planned/contacts?q=Landlord');

        self::assertResponseIsSuccessful();
        self::assertSame(['results' => [
            ['id' => $first->id(), 'label' => 'Landlord First s.r.o.', 'hint' => 'first@example.com'],
            ['id' => $second->id(), 'label' => 'Landlord Second', 'hint' => null],
        ]], json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testTheCounterpartyFieldOffersOnlyTheFirstPage(): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        foreach (range(1, PartyQuery::PER_PAGE + 1) as $number) {
            $em->persist(new Party(PartyKind::Person, \sprintf('Tenant %02d', $number), null, null, ''));
        }
        $em->flush();

        $this->client->request('GET', '/cash-flow/planned/contacts?q=Tenant');

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['results']);
        self::assertSame(array_map(static fn (int $number): string => \sprintf('Tenant %02d', $number), range(1, PartyQuery::PER_PAGE)), array_column($body['results'], 'label'));
    }

    private function item(): PlannedItem
    {
        $item = $this->itemOrNull();
        self::assertInstanceOf(PlannedItem::class, $item);

        return $item;
    }

    private function itemOrNull(): ?PlannedItem
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();

        return $em->getRepository(PlannedItem::class)->findOneBy([]);
    }

    /**
     * @return array<string, string>
     */
    private function dialogForm(string $url, string $form = '#planned-form'): array
    {
        $crawler = $this->client->request('GET', $url, server: [self::HEADER => '1']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('dialog#dialog-page'));

        /** @var array<string, string> $values */
        $values = $crawler->filter($form)->form()->getPhpValues();

        return $values;
    }

    /**
     * @param array<string, string> $values
     */
    private function dialogPost(string $url, array $values): Crawler
    {
        return $this->client->request('POST', $url, $values, server: [self::HEADER => '1']);
    }
}
