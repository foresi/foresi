<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Integration\Purchasing;

use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Margin\Application\CostShareCalculation;
use Foresi\Margin\Domain\CostShare;
use Foresi\Margin\Domain\DocumentAllocation;
use Foresi\Margin\Domain\LineAllocation;
use Foresi\Margin\Integration\Purchasing\MarginPanel;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Purchasing\Application\ReceivedDocumentInput;
use Foresi\Purchasing\Application\RecordReceivedDocument;
use Foresi\Purchasing\Contract\PurchasingSlots;
use Foresi\Purchasing\Domain\ExchangeRate;
use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\ReceivedDocumentLineInput;
use Foresi\Purchasing\Domain\VatRate;
use Foresi\Purchasing\Domain\VatRecapLine;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Margin panel of a received document and the dialogs that assign it and split its lines, as a staff user
 * sees them and a guest does not.
 */
final class MarginScreensTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private Party $supplier;
    private Party $clientA;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    public function testThePanelShowsEachLineWithItsShareAndASourceBadge(): void
    {
        $this->start(Role::Owner);
        $id = $this->record();

        $page = $this->client->request('GET', '/purchasing/'.$id);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-purchasing-margin]'));
        self::assertSame(['Hosting', 'Domain'], $page->filter('[data-margin-text]')->each(static fn (Crawler $text): string => trim($text->text())));
        $shares = $page->filter('[data-margin-share]');
        self::assertCount(2, $shares);
        self::assertSame('unassigned', $shares->first()->attr('data-margin-share'));
        self::assertStringContainsString('Unassigned', $shares->first()->text());
        self::assertStringContainsString('700.00', $shares->first()->text());
        self::assertStringContainsString('unassigned', $shares->first()->filter('span')->last()->text());
        self::assertCount(1, $page->filter('a[data-margin-assign]'));
        self::assertCount(2, $page->filter('a[data-margin-edit]'));
    }

    public function testAWholeDocumentIsAssignedToAClientAndTheOwnLineKeepsItsOwnSplit(): void
    {
        $this->start(Role::Owner);
        $id = $this->record();

        $dialog = $this->client->request('GET', '/margin/documents/'.$id.'/assign');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $dialog->filter('#margin-contact'));
        self::assertCount(1, $dialog->filter('a[data-margin-split-lines]'));
        $this->client->submit($dialog->filter('#margin-assign-form')->form(['type' => 'client', 'contactId' => $this->clientA->id(), 'query' => 'Client A s.r.o.']));

        self::assertResponseRedirects('/purchasing/'.$id, 303);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Client A s.r.o.', $page->filter('[data-margin-whole]')->text());
        self::assertSame(['document', 'document'], $page->filter('[data-margin-share]')->each(static fn (Crawler $share): ?string => $share->attr('data-margin-share')));
        self::assertStringContainsString('Client A s.r.o.', $page->filter('[data-margin-share]')->first()->text());
        self::assertSame(1, $this->stored(DocumentAllocation::class));
    }

    public function testALineIsSplitByPercentAndTheAssignmentIsCleared(): void
    {
        $this->start(Role::Owner);
        $id = $this->record();
        $key = $this->lineKeys($id)[0];

        $dialog = $this->client->request('GET', '/margin/documents/'.$id.'/lines/'.$key);
        self::assertResponseIsSuccessful();
        self::assertCount(4, $dialog->filter('[data-margin-row]'));
        $this->client->submit($dialog->filter('#margin-line-form')->form([
            'kind' => 'percent',
            'shares[0][type]' => 'client', 'shares[0][contactId]' => $this->clientA->id(), 'shares[0][percent]' => '30',
            'shares[1][type]' => 'own', 'shares[1][percent]' => '70',
        ]));

        self::assertResponseRedirects('/purchasing/'.$id, 303);
        $page = $this->client->followRedirect();
        $line = $page->filter('[data-margin-line="'.$key.'"]');
        self::assertSame(['line', 'line'], $line->filter('[data-margin-share]')->each(static fn (Crawler $share): ?string => $share->attr('data-margin-share')));
        self::assertStringContainsString('210.00', $line->filter('[data-margin-share]')->first()->text());
        self::assertStringContainsString('490.00', $line->filter('[data-margin-share]')->last()->text());
        self::assertStringContainsString('Own consumption', $line->filter('[data-margin-share]')->last()->text());

        $again = $this->client->request('GET', '/margin/documents/'.$id.'/lines/'.$key);
        self::assertSame('30', $again->filter('#margin-percent-0')->attr('value'));
        $this->client->request('POST', '/margin/documents/'.$id.'/lines/'.$key, ['_action' => 'clear', '_token' => $again->filter('#margin-line-form input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects('/purchasing/'.$id, 303);
        self::assertSame(0, $this->stored(LineAllocation::class));
    }

    public function testARefusalIsToldInTheForm(): void
    {
        $this->start(Role::Owner);
        $id = $this->record();
        $key = $this->lineKeys($id)[0];

        $dialog = $this->client->request('GET', '/margin/documents/'.$id.'/lines/'.$key);
        $page = $this->client->submit($dialog->filter('#margin-line-form')->form([
            'kind' => 'percent',
            'shares[0][type]' => 'own', 'shares[0][percent]' => '60',
            'shares[1][type]' => 'client', 'shares[1][contactId]' => $this->clientA->id(), 'shares[1][percent]' => '30',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('adding up to exactly 100', $page->filter('[data-margin-error]')->text());
        self::assertSame('60', $page->filter('#margin-percent-0')->attr('value'), 'What was typed stays.');
        self::assertSame(0, $this->stored(LineAllocation::class));
        self::assertSame(['unassigned', 'unassigned'], array_map(static fn (CostShare $share): string => $share->source()->value, self::getContainer()->get('doctrine.orm.entity_manager')->getRepository(CostShare::class)->findBy([], ['id' => 'ASC'])));
    }

    public function testAClientThatIsNotPickedIsToldAndNothingIsSaved(): void
    {
        $this->start(Role::Owner);
        $id = $this->record();

        $dialog = $this->client->request('GET', '/margin/documents/'.$id.'/assign');
        $page = $this->client->submit($dialog->filter('#margin-assign-form')->form(['type' => 'client', 'contactId' => '', 'query' => 'Cli']));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $page->filter('[data-margin-error]'));
        self::assertSame(0, $this->stored(DocumentAllocation::class));
    }

    public function testTheLineDialogTellsWhatIsWrongInWords(): void
    {
        $this->start(Role::Owner);
        $id = $this->record();
        $key = $this->lineKeys($id)[0];
        $dialog = $this->client->request('GET', '/margin/documents/'.$id.'/lines/'.$key);

        $page = $this->client->submit($dialog->filter('#margin-line-form')->form(['kind' => 'percent', 'shares[0][type]' => 'client', 'shares[0][percent]' => '100']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Pick the client of a row from the list.', $page->filter('[data-margin-error]')->text());

        $page = $this->client->submit($page->filter('#margin-line-form')->form(['kind' => 'fixed', 'shares[0][type]' => 'own', 'shares[0][amount]' => '-5']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('An amount is a number in CZK with at most two decimals.', $page->filter('[data-margin-error]')->text());

        $page = $this->client->submit($page->filter('#margin-line-form')->form(['kind' => 'percent', 'shares[0][type]' => '', 'shares[0][percent]' => '100']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose the firm itself or a client.', $page->filter('[data-margin-error]')->text());
        self::assertSame(0, $this->stored(LineAllocation::class));
    }

    public function testAKindThatIsNoneOfTheThreeIsToldAndNothingIsSaved(): void
    {
        $this->start(Role::Owner);
        $id = $this->record();
        $key = $this->lineKeys($id)[0];
        $dialog = $this->client->request('GET', '/margin/documents/'.$id.'/lines/'.$key);
        $values = $dialog->filter('#margin-line-form')->form(['shares[0][type]' => 'own'])->getPhpValues();

        $page = $this->client->request('POST', '/margin/documents/'.$id.'/lines/'.$key, ['kind' => 'sideways'] + $values);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The kind is whole, percent or fixed.', $page->filter('[data-margin-error]')->text());
        self::assertSame(0, $this->stored(LineAllocation::class));
    }

    public function testThePanelAppearsOnlyInTheDocumentSectionsOfADocument(): void
    {
        $this->start(Role::Owner);
        $id = $this->record();
        $this->client->request('GET', '/purchasing/'.$id);
        $panel = self::getContainer()->get(MarginPanel::class);

        self::assertCount(1, [...$panel->items(PurchasingSlots::DOCUMENT_SECTIONS, ['documentId' => $id])]);
        self::assertSame([], [...$panel->items('other.slot', ['documentId' => $id])]);
        self::assertSame([], [...$panel->items(PurchasingSlots::DOCUMENT_SECTIONS, ['documentId' => 5])]);
        self::assertSame([], [...$panel->items(PurchasingSlots::DOCUMENT_SECTIONS, [])]);
    }

    public function testEnterInTheFormSavesAndNeverClears(): void
    {
        $this->start(Role::Owner);
        $id = $this->record();
        $key = $this->lineKeys($id)[0];
        $dialog = $this->client->request('GET', '/margin/documents/'.$id.'/lines/'.$key);
        $this->client->submit($dialog->filter('#margin-line-form')->form(['kind' => 'whole', 'shares[0][type]' => 'own']));
        self::assertSame(1, $this->stored(LineAllocation::class));

        // What Enter presses is the first submit button associated with the form; the footer buttons come later.
        $again = $this->client->request('GET', '/margin/documents/'.$id.'/lines/'.$key);
        $form = $again->filter('#margin-line-form')->form();
        $first = $again->filter('#margin-line-form button[type="submit"]')->first();
        self::assertSame('save', $first->attr('value'));
        $this->client->request('POST', '/margin/documents/'.$id.'/lines/'.$key, $form->getPhpValues() + ['_action' => $first->attr('value')]);
        self::assertResponseRedirects('/purchasing/'.$id, 303);
        self::assertSame(1, $this->stored(LineAllocation::class), 'Enter did not clear the split.');

        $dialog = $this->client->request('GET', '/margin/documents/'.$id.'/assign');
        self::assertSame('save', $dialog->filter('#margin-assign-form button[type="submit"]')->first()->attr('value'));
    }

    public function testAForeignDocumentWithoutARateShowsTheKnownGapNote(): void
    {
        $this->start(Role::Owner);
        $id = $this->record(Currency::EUR);
        $connection = $this->browser->em()->getConnection();
        $recap = json_decode(\is_string($stored = $connection->fetchOne('SELECT vat_recap FROM received_document WHERE id = ?', [$id])) ? $stored : '[]', true, flags: \JSON_THROW_ON_ERROR);
        $recap = array_map(static fn (mixed $row): array => ['base_czk' => null] + (array) $row, (array) $recap);
        $connection->executeStatement('UPDATE received_document SET exchange_rate = NULL, vat_recap = ? WHERE id = ?', [json_encode($recap, \JSON_THROW_ON_ERROR), $id]);
        $this->browser->em()->clear();
        self::getContainer()->get(CostShareCalculation::class)->recalculate($id);

        $page = $this->client->request('GET', '/purchasing/'.$id);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-margin-gap]'));
        self::assertCount(2, $page->filter('[data-margin-line-gap]'));
        self::assertCount(0, $page->filter('[data-margin-share]'));
    }

    public function testTheContactSearchOffersActiveContactsByName(): void
    {
        $this->start(Role::Owner);

        $this->client->request('GET', '/margin/contacts?q=Client');
        self::assertResponseIsSuccessful();
        self::assertSame(['results' => [['id' => $this->clientA->id(), 'label' => 'Client A s.r.o.', 'hint' => '27082440']]], json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->client->request('GET', '/margin/contacts?q=Cl');
        self::assertSame(['results' => []], json_decode((string) $this->client->getResponse()->getContent(), true), 'Two letters are too few.');
    }

    public function testAGuestHasNoPanelAndCannotDecide(): void
    {
        $this->start(Role::Owner);
        $id = $this->record();
        $membership = $this->browser->em()->getRepository(Membership::class)->findOneBy([]);
        self::assertInstanceOf(Membership::class, $membership);
        $membership->changeRole(Role::Guest, $this->browser->em()->find(Party::class, $this->clientA->id()));
        $this->browser->em()->flush();

        foreach (['/purchasing/'.$id, '/margin/documents/'.$id.'/assign', '/margin/documents/'.$id.'/lines/rate:21', '/margin/contacts?q=Client'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->client->request('POST', '/margin/documents/'.$id.'/assign', ['type' => 'own', '_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->stored(DocumentAllocation::class));
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

    /**
     * A recorded document of two lines, 700 and 300 CZK without VAT.
     */
    private function record(Currency $currency = Currency::CZK): string
    {
        $saved = self::getContainer()->get(RecordReceivedDocument::class)->create(new ReceivedDocumentInput(
            Currency::CZK === $currency ? ReceivedDocumentKind::TaxInvoice : ReceivedDocumentKind::ForeignInvoice,
            Currency::CZK === $currency ? PurchaseTaxTreatment::Domestic : PurchaseTaxTreatment::EuService,
            $this->supplier->id(),
            'INV-1',
            '2026123',
            new \DateTimeImmutable('2026-11-18'),
            new \DateTimeImmutable('2026-11-18'),
            null,
            new \DateTimeImmutable('2026-11-18'),
            $currency,
            Money::of(Currency::CZK === $currency ? '1210.00' : '1000.00', $currency),
            Currency::CZK === $currency ? null : ExchangeRate::of('25'),
            null,
            [new VatRecapLine(VatRate::of('21'), Money::of('1000.00', $currency), Money::of(Currency::CZK === $currency ? '210.00' : '0.00', $currency))],
            lines: [
                new ReceivedDocumentLineInput('Hosting', Money::of('700.00', $currency), '21'),
                new ReceivedDocumentLineInput('Domain', Money::of('300.00', $currency), '21'),
            ],
        ));
        $this->browser->em()->flush();

        return $saved->document->id();
    }

    /**
     * @return list<string>
     */
    private function lineKeys(string $id): array
    {
        $page = $this->client->request('GET', '/purchasing/'.$id);

        return $page->filter('[data-margin-line]')->each(static fn (Crawler $line): string => (string) $line->attr('data-margin-line'));
    }

    /**
     * @param class-string $class
     */
    private function stored(string $class): int
    {
        return $this->browser->em()->getRepository($class)->count([]);
    }
}
