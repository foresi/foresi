<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Integration\ReceivedDocuments;

use Foresi\CashFlow\Application\BalanceWrites;
use Foresi\CashFlow\Application\RecordCheckpointInput;
use Foresi\ExchangeRate\Contract\RateSource;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\ReceivedDocuments\Application\ReceivedDocumentInput;
use Foresi\ReceivedDocuments\Application\RecordReceivedDocument;
use Foresi\ReceivedDocuments\Domain\PurchaseTaxTreatment;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentKind;
use Foresi\ReceivedDocuments\Domain\VatRate;
use Foresi\ReceivedDocuments\Domain\VatRecapLine;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
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
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The open documents in the sheet of a cell on the Cash flow page: their kind,
 * the day they are counted on and their flags, a link that opens the document
 * in place, and the small dialog that sets or clears the expected day (Float
 * and Xero's expected payment date).
 */
final class ExpectedDocumentsTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-19 10:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $rates = new FakeRateSource();
        $rates->publish('2026-11-18', '24.500', '21.000');
        self::getContainer()->set(RateSource::class, $rates);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $user = $this->browser->seed();
        $this->browser->signIn();
        $em = $this->browser->em();
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $em->persist($firm);
        $account = FirmAccounts::add($em, self::IBAN)->id();
        $em->flush();
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));
        self::getContainer()->get(BalanceWrites::class)->checkpoint(new RecordCheckpointInput($account, '2026-11-19', '100000.00'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheItemsOfACellLinkToTheirDocumentAndOfferTheExpectedDay(): void
    {
        $overdue = $this->received('LATE-1', '2026-10-01', '2026-11-05');
        $undated = $this->received('NODUE-1', '2026-11-10', null);
        $onTime = $this->received('LATER-1', '2026-11-15', '2026-12-10');

        $page = $this->client->request('GET', '/cash-flow');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('[data-cash-flow-documents]'), 'The list under the table is gone: the cells open their items.');

        $late = $this->item($overdue);
        self::assertStringContainsString('LATE-1 Landlord Example s.r.o.', $late->text());
        self::assertStringContainsString('Received document', $late->text());
        self::assertStringContainsString('Overdue', $late->text());
        self::assertStringContainsString('12,100', $late->text());
        // The document opens in place, in the same sheet.
        self::assertSame('/received-documents/'.$overdue, $late->filter('[data-in-place] a[data-cash-flow-item-link]')->attr('href'));
        self::assertSame('/cash-flow/expected/received_document/'.$overdue, $late->filter('a[data-cash-flow-item-expected][data-dialog]')->attr('href'));
        self::assertSame('Set expected day', $late->filter('a[data-cash-flow-item-expected]')->text());
        self::assertCount(0, $late->filter('[data-due]'), 'Counted on its due day, it says no more.');
        self::assertStringContainsString('No due date', $this->item($undated)->text());
        self::assertStringContainsString('Dec 10', $this->item($onTime)->text());
    }

    public function testTheExpectedDayIsSetInTheDialogAndClearedAgain(): void
    {
        $document = $this->received('LATE-2', '2026-10-01', '2026-11-05');
        $url = '/cash-flow/expected/received_document/'.$document;

        $crawler = $this->dialog('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('dialog#dialog-page'));
        self::assertSelectorNotExists('[data-cash-flow-expected-clear]');
        /** @var array<string, string> $values */
        $values = $crawler->filter('#cash-flow-expected-form')->form()->getPhpValues();
        self::assertSame('2026-11-05', $values['expectedOn'], 'It starts from the due day.');

        $this->dialog('POST', $url, ['expectedOn' => '2026-12-03'] + $values);
        self::assertSame('{"location":"\\/cash-flow"}', $this->client->getResponse()->getContent());
        $row = $this->item($document);
        self::assertStringContainsString('Dec 3', $row->text());
        self::assertStringContainsString('Expected day', $row->text());
        self::assertSame('due Nov 5', $row->filter('[data-due]')->text(), 'Moved by an expected day, it says its due day too.');
        self::assertSame('Change expected day', $row->filter('a[data-cash-flow-item-expected]')->text());
        self::assertStringNotContainsString('Overdue', $row->text(), 'Counted on the day it is expected, it is no longer overdue.');

        $crawler = $this->dialog('GET', $url.'?view=weeks');
        self::assertSelectorExists('[data-cash-flow-expected-clear]');
        /** @var array<string, string> $values */
        $values = $crawler->filter('#cash-flow-expected-form')->form()->getPhpValues();
        self::assertSame('2026-12-03', $values['expectedOn']);
        $this->dialog('POST', $url.'?view=weeks', ['clear' => '1'] + $values);
        self::assertSame('{"location":"\\/cash-flow?view=weeks"}', $this->client->getResponse()->getContent());
        self::assertStringContainsString('Overdue', $this->item($document)->text());
    }

    public function testAnEmptyDayIsRefusedAndADocumentOutsideTheForecastIsNotFound(): void
    {
        $document = $this->received('LATE-3', '2026-10-01', '2026-11-05');
        $url = '/cash-flow/expected/received_document/'.$document;
        /** @var array<string, string> $values */
        $values = $this->dialog('GET', $url)->filter('#cash-flow-expected-form')->form()->getPhpValues();

        $this->dialog('POST', $url, ['expectedOn' => ''] + $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#cash-flow-expected-form', 'Enter a day, or clear the expected day.');

        $this->dialog('GET', '/cash-flow/expected/received_document/0190f0f0-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);
        $this->dialog('GET', '/cash-flow/expected/planned_item/'.$document);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The item of a received document in the sheet of money out of the month it is counted in.
     */
    private function item(string $document): Crawler
    {
        foreach (['2026-11', '2026-12'] as $period) {
            $sheet = $this->client->request('GET', '/cash-flow/items?period='.$period.'&direction=out', server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => 'sheet']);
            self::assertResponseIsSuccessful();
            $item = $sheet->filter('[data-cash-flow-item][data-subject="received_document-'.$document.'"]');
            if (1 === $item->count()) {
                return $item;
            }
        }
        self::fail('The document is in no sheet of money out.');
    }

    /**
     * @param array<string, string> $values
     */
    private function dialog(string $method, string $url, array $values = []): Crawler
    {
        return $this->client->request($method, $url, $values, server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1']);
    }

    /**
     * A tax invoice of 10,000 CZK and 21 % VAT, so 12,100 CZK to pay.
     */
    private function received(string $number, string $issued, ?string $due): string
    {
        $em = $this->browser->em();
        $supplier = new Party(PartyKind::Company, 'Landlord Example s.r.o.', new RegistrationNumber('CZ', '11223344'), VatId::fromString('CZ11223344'), '');
        $em->persist($supplier);
        $em->persist(new PartyAddress($supplier, AddressType::Billing, new Address('Supplier Street 2', 'Example City', '110 00', 'CZ')));
        $em->flush();
        $saved = self::getContainer()->get(RecordReceivedDocument::class)->create(new ReceivedDocumentInput(
            ReceivedDocumentKind::TaxInvoice,
            PurchaseTaxTreatment::Domestic,
            $supplier->id(),
            $number,
            '2026123',
            new \DateTimeImmutable($issued),
            new \DateTimeImmutable($issued),
            null === $due ? null : new \DateTimeImmutable($due),
            new \DateTimeImmutable($issued),
            Currency::CZK,
            Money::ofMinor(1_210_000, Currency::CZK),
            null,
            null,
            [new VatRecapLine(VatRate::of('21'), Money::ofMinor(1_000_000, Currency::CZK), Money::ofMinor(210_000, Currency::CZK))],
        ));
        $em->flush();

        return $saved->document->id();
    }
}
