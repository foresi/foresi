<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Integration\ReceivedDocuments;

use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\CashFlow\Contract\PairingState;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\ReceivedDocuments\Application\Query\ReceivedDocumentFilter;
use Foresi\ReceivedDocuments\Application\Query\ReceivedDocumentQueries;
use Foresi\ReceivedDocuments\Application\ReceivedDocumentInput;
use Foresi\ReceivedDocuments\Application\RecordReceivedDocument;
use Foresi\ReceivedDocuments\Domain\PurchaseTaxTreatment;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentKind;
use Foresi\ReceivedDocuments\Domain\VatRate;
use Foresi\ReceivedDocuments\Domain\VatRecapLine;
use Foresi\ReceivedDocuments\Integration\CashFlow\PairingToConfirmFilter;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\ExchangeRate\Support\FakeRateSource;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The Plan panel of a received document, the dialog that picks an occurrence, the "to confirm" condition of the
 * list and the paired documents on the sheet of a planned item. Today is 19 November 2026.
 */
final class PairingScreensTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private Party $supplier;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-19 10:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $rates = new FakeRateSource();
        $rates->publish('2026-11-18', '24.500', '21.000');
        self::getContainer()->set(RateSource::class, $rates);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $user = $this->browser->seed('en', Role::Owner);
        $this->browser->signIn();
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->supplier = new Party(PartyKind::Company, 'Cloud Host s.r.o.', new RegistrationNumber('CZ', '11223344'), null, '');
        $em->persist($this->supplier);
        $em->flush();
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAnAutomaticPairingIsShownWithItsItemAndMonthAndCanBeUnpaired(): void
    {
        $item = $this->monthly('Cloud Host', '1210.00');
        $id = $this->record('2026-11-18', '1210.00');

        $page = $this->client->request('GET', '/received-documents/'.$id);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-purchasing-pairing][data-pairing-state="auto"]'));
        self::assertStringContainsString('Cloud Host', $page->filter('[data-pairing-current]')->text());
        self::assertStringContainsString('11/2026', $page->filter('[data-pairing-current]')->text());
        self::assertCount(1, $page->filter('[data-pairing-current] [data-pairing-auto]'));
        self::assertStringContainsString($item->id(), (string) $page->filter('[data-pairing-current] a')->attr('href'));

        $this->client->submit($page->filter('[data-pairing-unpair]')->form());

        self::assertResponseRedirects('/received-documents/'.$id);
        self::assertSame(PairingState::Declined, $this->state($id));
        $page = $this->client->followRedirect();
        self::assertCount(1, $page->filter('[data-pairing-state="declined"] [data-pairing-declined]'));
        self::assertCount(1, $page->filter('[data-pairing-choose]'));
    }

    public function testAProposalListsItsCandidatesAndAPersonConfirmsOne(): void
    {
        $cloud = $this->monthly('Cloud Host', '500.00');
        $this->monthly('Cloud Host backup', '600.00');
        $id = $this->record('2026-11-18', '1210.00');

        $page = $this->client->request('GET', '/received-documents/'.$id);

        self::assertCount(1, $page->filter('[data-pairing-state="proposed"]'));
        self::assertCount(2, $page->filter('[data-pairing-candidate]'));
        self::assertCount(1, $page->filter('[data-pairing-other]'));
        self::assertCount(1, $page->filter('[data-pairing-decline]'));

        $this->client->submit($page->filter('[data-pairing-candidate="'.$cloud->id().'"] form')->form());
        $page = $this->client->followRedirect();

        self::assertSame(PairingState::Confirmed, $this->state($id));
        self::assertCount(1, $page->filter('[data-pairing-state="confirmed"] [data-pairing-current]'));
        self::assertCount(0, $page->filter('[data-pairing-auto]'));
    }

    public function testADocumentWithoutAProposalIsPairedFromTheDialog(): void
    {
        $item = $this->monthly('Cloud Host', '500.00', null);
        $id = $this->record('2026-11-18', '1210.00');

        $page = $this->client->request('GET', '/received-documents/'.$id);
        self::assertCount(1, $page->filter('[data-pairing-state="none"] [data-pairing-none]'));

        $dialog = $this->client->request('GET', '/received-documents/'.$id.'/pairing/choose');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $dialog->filter('select[name="choice"] optgroup[label="Cloud Host"]'));
        $form = $dialog->filter('#pairing-form')->form();
        $form->setValues(['choice' => $item->id().'|2026-11-20']);
        $this->client->submit($form);

        self::assertResponseRedirects('/received-documents/'.$id);
        self::assertSame(PairingState::Confirmed, $this->state($id));
    }

    public function testADayThatIsNoOccurrenceIsRefusedWithAMessage(): void
    {
        $item = $this->monthly('Cloud Host', '500.00', null);
        $id = $this->record('2026-11-18', '1210.00');
        $dialog = $this->client->request('GET', '/received-documents/'.$id.'/pairing/choose');
        $token = (string) $dialog->filter('#pairing-form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/received-documents/'.$id.'/pairing', ['_token' => $token, 'choice' => $item->id().'|2026-11-21']);

        self::assertResponseRedirects('/received-documents/'.$id);
        $page = $this->client->followRedirect();
        self::assertCount(1, $page->filter('[role="alert"]'));
        self::assertNull($this->state($id));
    }

    public function testADeclinedDocumentShowsNotPairedAndOffersToPair(): void
    {
        $this->monthly('Cloud Host', '1210.00');
        $id = $this->record('2026-11-18', '1210.00');
        $page = $this->client->request('GET', '/received-documents/'.$id);
        $this->client->submit($page->filter('[data-pairing-unpair]')->form());

        $page = $this->client->request('GET', '/received-documents/'.$id);

        $panel = $page->filter('[data-pairing-state="declined"]');
        self::assertCount(1, $panel);
        self::assertStringContainsString('Not paired', $panel->text());
        self::assertStringContainsString('Pair…', $panel->filter('[data-pairing-choose]')->text());
        self::assertCount(0, $panel->filter('[data-pairing-unpair], [data-pairing-candidate]'));
    }

    public function testAProposalOffersAnotherItemAndNotToPair(): void
    {
        $this->monthly('Cloud Host', '500.00');
        $this->monthly('Cloud Host backup', '600.00');
        $id = $this->record('2026-11-18', '1210.00');

        $page = $this->client->request('GET', '/received-documents/'.$id);

        $panel = $page->filter('[data-pairing-state="proposed"]');
        self::assertStringContainsString('Another item…', $panel->filter('[data-pairing-other]')->text());
        self::assertStringContainsString('Do not pair', $panel->filter('[data-pairing-decline]')->text());
        self::assertStringContainsString('may belong to one of these planned items', $panel->text());
        self::assertCount(2, $panel->filter('[data-pairing-confirm]'));
        self::assertCount(0, $panel->filter('[data-pairing-choose], [data-pairing-unpair]'));
    }

    public function testDecliningAProposalLeavesTheDocumentUnpaired(): void
    {
        $this->monthly('Cloud Host', '500.00');
        $this->monthly('Cloud Host backup', '600.00');
        $id = $this->record('2026-11-18', '1210.00');
        $page = $this->client->request('GET', '/received-documents/'.$id);

        $this->client->submit($page->filter('[data-pairing-decline]')->ancestors()->filter('form')->form());

        self::assertResponseRedirects('/received-documents/'.$id);
        self::assertSame(PairingState::Declined, $this->state($id));
        $page = $this->client->followRedirect();
        self::assertCount(1, $page->filter('[data-pairing-state="declined"] [data-pairing-declined]'));
    }

    public function testAConfirmedPairingIsNotMarkedAutomatic(): void
    {
        $item = $this->monthly('Cloud Host', '500.00');
        $this->monthly('Cloud Host backup', '600.00');
        $id = $this->record('2026-11-18', '1210.00');
        $page = $this->client->request('GET', '/received-documents/'.$id);
        $this->client->submit($page->filter('[data-pairing-candidate="'.$item->id().'"] form')->form());

        $page = $this->client->followRedirect();

        $panel = $page->filter('[data-pairing-state="confirmed"]');
        self::assertStringContainsString('Paired with', $panel->text());
        self::assertStringContainsString('Cloud Host', $panel->filter('[data-pairing-current]')->text());
        self::assertStringNotContainsString('(automatically)', $panel->text());
        self::assertCount(1, $panel->filter('[data-pairing-unpair]'));
    }

    public function testAGuestCannotChoosePairOrUnpairAndSeesNoPanel(): void
    {
        $item = $this->monthly('Cloud Host', '500.00', null);
        $id = $this->record('2026-11-18', '1210.00');
        $dialog = $this->client->request('GET', '/received-documents/'.$id.'/pairing/choose');
        $token = (string) $dialog->filter('#pairing-form input[name="_token"]')->attr('value');
        $em = $this->browser->em();
        $user = new User('guest@example.com', 'hash', 'Example Guest');
        $customer = new Party(PartyKind::Company, 'Client A s.r.o.', new RegistrationNumber('CZ', '87654321'), null, '');
        $em->persist($customer);
        $em->persist($user);
        $em->persist(new Membership($user, Role::Guest, $customer));
        $em->flush();
        $this->client->loginUser(new SecurityUser($user->id(), $user->email(), 'hash'), 'main');

        $this->client->request('GET', '/received-documents/'.$id.'/pairing/choose');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/received-documents/'.$id.'/pairing', ['_token' => $token, 'choice' => $item->id().'|2026-11-20']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/received-documents/'.$id.'/pairing/unpair', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        $page = $this->client->request('GET', '/received-documents/'.$id);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $page->filter('[data-purchasing-pairing]'));
        self::assertNull($this->state($id));
    }

    public function testAnInvalidTokenIsRefused(): void
    {
        $id = $this->record('2026-11-18', '1210.00');

        $this->client->request('POST', '/received-documents/'.$id.'/pairing/unpair', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheConditionToConfirmFindsTheDocumentsWithAProposal(): void
    {
        $this->monthly('Cloud Host', '500.00');
        $this->monthly('Cloud Host backup', '600.00');
        $proposed = $this->record('2026-11-18', '1210.00');
        $other = $this->supplier('Other Host s.r.o.', '55667788');
        $unpaired = $this->record('2026-11-18', '300.00', $other);
        $queries = self::getContainer()->get(ReceivedDocumentQueries::class);

        $toConfirm = $queries->rows(new ReceivedDocumentFilter('all', view: ViewFilter::all(new Condition(PairingToConfirmFilter::FIELD, 'is', true))), 0, 20);
        $rest = $queries->rows(new ReceivedDocumentFilter('all', view: ViewFilter::all(new Condition(PairingToConfirmFilter::FIELD, 'is', false))), 0, 20);

        self::assertSame([$proposed], array_map(static fn ($row): string => $row->id, $toConfirm));
        self::assertSame([$unpaired], array_map(static fn ($row): string => $row->id, $rest));
    }

    public function testTheListOffersTheConditionAmongItsFilters(): void
    {
        $page = $this->client->request('GET', '/received-documents?tab=recorded');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Pairing to confirm', $page->html());
    }

    public function testThePlannedItemListsItsPairedDocumentsPerOccurrence(): void
    {
        $item = $this->monthly('Cloud Host', '1210.00');
        $id = $this->record('2026-11-18', '1210.00');

        $page = $this->client->request('GET', '/cash-flow/planned-costs/'.$item->id());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-planned-paired] [data-paired-occurrence="2026-11-20"] [data-paired-document]'));
        self::assertStringContainsString('/received-documents/'.$id, (string) $page->filter('[data-paired-document] a')->attr('href'));
        self::assertStringContainsString('Cloud Host', $page->filter('[data-paired-document]')->text());
    }

    private function state(string $documentId): ?PairingState
    {
        $this->browser->em()->clear();

        return self::getContainer()->get(CashFlowStore::class)->pairing('received_document', $documentId)?->state();
    }

    private function monthly(string $name, string $amount, ?string $counterparty = 'self'): PlannedItem
    {
        return self::getContainer()->get(PlannedItemWrites::class)->create(new CreatePlannedItemInput(
            'out', $name, 'software', $amount, '2026-05-20', 'CZK', 'month', 1, false, null, null, 'self' === $counterparty ? $this->supplier->id() : $counterparty, true,
        ));
    }

    private function supplier(string $name, string $number): Party
    {
        $party = new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', $number), null, '');
        $this->browser->em()->persist($party);
        $this->browser->em()->flush();

        return $party;
    }

    private function record(string $day, string $total, ?Party $supplier = null): string
    {
        $minor = Money::of($total, Currency::of('CZK'))->minor();
        $base = intdiv($minor * 100, 121);
        $saved = self::getContainer()->get(RecordReceivedDocument::class)->create(new ReceivedDocumentInput(
            ReceivedDocumentKind::TaxInvoice,
            PurchaseTaxTreatment::Domestic,
            ($supplier ?? $this->supplier)->id(),
            'INV-'.$day,
            '2026123',
            new \DateTimeImmutable($day),
            new \DateTimeImmutable($day),
            null,
            new \DateTimeImmutable($day),
            Currency::of('CZK'),
            Money::ofMinor($minor, Currency::of('CZK')),
            null,
            null,
            [new VatRecapLine(VatRate::of('21'), Money::ofMinor($base, Currency::of('CZK')), Money::ofMinor($minor - $base, Currency::of('CZK')))],
        ));
        $this->browser->em()->flush();

        return $saved->document->id();
    }
}
