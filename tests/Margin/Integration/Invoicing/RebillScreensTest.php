<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Integration\Invoicing;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Margin\Domain\RebillLink;
use Foresi\Margin\Domain\RebillPairing;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The rebill dialogs on the recurring invoice and the issued invoice, their badges, and the "Not rebilled" badge and
 * sheet of the Margin page, as a member sees them and a guest does not. Data comes in through the API, the screens are
 * read signed in. Today is 10 December 2026.
 */
final class RebillScreensTest extends ApiTestCase
{
    private string $writer;
    private string $supplier;
    private string $item;
    private string $invoiceId;
    private string $invoiceLine;
    private string $templateId;
    private string $templateLine;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-12-10 09:00:00', 'UTC'));
        $this->writer = $this->issued([ApiScope::from('margin:write'), ApiScope::from('margin:read'), ApiScope::from('cashflow:write'), ApiScope::from('invoices:write'), ApiScope::from('received_documents:write')]);
        $this->supplier = $this->flushed(function (): string {
            $party = new Party(PartyKind::Person, 'Cloud Host', null, null, '+420 000 000 001');
            $this->em()->persist($party);

            return $party->id();
        });
        $this->item = $this->planned('Cloud Host');
        $this->send('PUT', '/api/v1/margin/planned-items/'.$this->item.'/allocation', ['allocation' => ['kind' => 'whole', 'shares' => [['recipient' => $this->customerId]]], 'from_month' => '2026-12']);
        $this->send('POST', '/api/v1/invoices', ['customer_id' => $this->customerId, 'issue_date' => '2026-12-07', 'lines' => [['text' => 'Hosting', 'unit_price' => '1000.00', 'unit' => 'month']]]);
        $this->invoiceId = $this->id();
        $this->send('GET', '/api/v1/invoices/'.$this->invoiceId);
        $this->invoiceLine = $this->lineId();
        $this->send('POST', '/api/v1/recurring-invoices', ['from_invoice_id' => $this->invoiceId, 'delivery' => 'review', 'first_issue_on' => '2027-01-01']);
        $this->templateId = $this->id();
        $this->templateLine = $this->lineId();

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => IdentityFixtures::EMAIL, '_password' => IdentityFixtures::PASSWORD]));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheRecurringScreenOffersTheDialogAndShowsTheCostALineRebills(): void
    {
        $page = $this->client->request('GET', '/invoices/recurring/'.$this->templateId);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-recurring-line="'.$this->templateLine.'"] a[data-margin-rebill-open]'));
        self::assertCount(0, $page->filter('[data-margin-rebill-link]'));

        $dialog = $this->client->request('GET', '/margin/rebills/recurring-lines/'.$this->templateLine);
        self::assertResponseIsSuccessful();
        self::assertSame(['', $this->item], $dialog->filter('#margin-rebill-item option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertStringContainsString('Cloud Host', $dialog->filter('#margin-rebill-item')->text());

        $this->post('/margin/rebills/recurring-lines/'.$this->templateLine, ['plannedItemId' => $this->item], $dialog);
        self::assertResponseRedirects('/invoices/recurring/'.$this->templateId);
        self::assertSame(1, $this->em()->getRepository(RebillLink::class)->count([]));
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Rebills Cloud Host', $page->filter('[data-recurring-line="'.$this->templateLine.'"] [data-margin-rebill-link]')->text());
        self::assertCount(0, $page->filter('[data-margin-rebill-warning]'));

        // None removes it.
        $dialog = $this->client->request('GET', '/margin/rebills/recurring-lines/'.$this->templateLine);
        self::assertSame($this->item, $dialog->filter('#margin-rebill-item option[selected]')->attr('value'));
        $this->post('/margin/rebills/recurring-lines/'.$this->templateLine, ['plannedItemId' => ''], $dialog);
        self::assertResponseRedirects();
        self::assertSame(0, $this->em()->getRepository(RebillLink::class)->count([]));
        self::assertCount(0, $this->client->followRedirect()->filter('[data-margin-rebill-link]'));
    }

    public function testACustomerWhoBearsNothingOfTheCostIsWarnedAfterSaving(): void
    {
        $other = $this->planned('Voice API');
        $dialog = $this->client->request('GET', '/margin/rebills/recurring-lines/'.$this->templateLine);
        $this->post('/margin/rebills/recurring-lines/'.$this->templateLine, ['plannedItemId' => $other], $dialog);
        self::assertResponseRedirects();
        $page = $this->client->followRedirect();

        self::assertSame(1, $this->em()->getRepository(RebillLink::class)->count([]), 'the warning does not stop the link');
        self::assertStringContainsString('bears no share of this cost', $page->filter('[data-recurring-detail] [role="status"]')->last()->text());
        self::assertCount(1, $page->filter('[data-margin-rebill-warning="customer_not_a_recipient"]'));
    }

    public function testAnIssuedLineIsPairedWithAnOccurrenceAndUnpaired(): void
    {
        $page = $this->client->request('GET', '/invoices/'.$this->invoiceId);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-invoice-line="'.$this->invoiceLine.'"] a[data-margin-rebill-pair]'));
        self::assertCount(0, $page->filter('a[data-margin-rebill-unpair]'));

        $dialog = $this->client->request('GET', '/margin/rebills/invoice-lines/'.$this->invoiceLine);
        self::assertResponseIsSuccessful();
        self::assertSame('2026-12-07', $dialog->filter('#margin-rebill-day')->attr('value'), 'it starts at the tax point');

        // A day before the first occurrence is told in the form.
        $this->post('/margin/rebills/invoice-lines/'.$this->invoiceLine, ['plannedItemId' => $this->item, 'occurrenceOn' => '2026-10-01'], $dialog);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('no occurrence on this day', $this->client->getCrawler()->filter('[data-margin-error]')->text());
        $this->post('/margin/rebills/invoice-lines/'.$this->invoiceLine, ['plannedItemId' => '', 'occurrenceOn' => '2026-12-05'], $dialog);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getRepository(RebillPairing::class)->count([]));

        $this->post('/margin/rebills/invoice-lines/'.$this->invoiceLine, ['plannedItemId' => $this->item, 'occurrenceOn' => '2026-12-07'], $dialog);
        self::assertResponseRedirects('/invoices/'.$this->invoiceId);
        self::assertSame('2026-12-05', $this->em()->getRepository(RebillPairing::class)->findOneBy([])?->occurrenceOn()->format('Y-m-d'), 'the occurrence that holds the tax point is stored');
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Rebills Cloud Host · Dec 2026', $page->filter('[data-invoice-line="'.$this->invoiceLine.'"] [data-margin-rebill-pairing]')->text());
        self::assertCount(0, $page->filter('a[data-margin-rebill-pair]'));
        self::assertCount(1, $page->filter('a[data-margin-rebill-unpair]'));

        $dialog = $this->client->request('GET', '/margin/rebills/invoice-lines/'.$this->invoiceLine.'/unpair');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Cloud Host', $dialog->filter('[data-margin-rebill-unpair-form]')->text());
        $this->post('/margin/rebills/invoice-lines/'.$this->invoiceLine.'/unpair', [], $dialog);
        self::assertResponseRedirects('/invoices/'.$this->invoiceId);
        self::assertSame(0, $this->em()->getRepository(RebillPairing::class)->count([]));
        self::assertCount(0, $this->client->followRedirect()->filter('[data-margin-rebill-pairing]'));
    }

    public function testACostPaidAndNotRebilledIsABadgeOnTheClientAndListedInItsSheet(): void
    {
        $this->send('PUT', '/api/v1/margin/recurring-lines/'.$this->templateLine.'/rebills', ['planned_item_id' => $this->item]);
        $this->send('POST', '/api/v1/received-documents', ['kind' => 'tax_invoice', 'supplier_id' => $this->supplier, 'supplier_number' => 'CH-1', 'issue_date' => '2026-12-02', 'due_date' => '2026-12-16', 'received_date' => '2026-12-03', 'total' => '12100.00', 'vat_recap' => ['21:10000.00:2100.00']]);
        $document = $this->id();
        $this->send('POST', '/api/v1/cash-flow/pairings', ['subject_type' => 'received_document', 'subject_id' => $document, 'planned_item_id' => $this->item, 'occurrence_on' => '2026-12-05']);
        self::assertResponseIsSuccessful();

        $page = $this->client->request('GET', '/margin?from=2026-12&to=2026-12');
        self::assertResponseIsSuccessful();
        $badges = $page->filter('[data-margin-unrebilled]');
        self::assertCount(1, $badges);
        self::assertSame('Not rebilled 1', trim($badges->text()));
        self::assertSame('costs', $badges->ancestors()->filter('tr')->attr('data-line'));

        $sheet = $this->client->request('GET', '/margin/cell?row=client:'.$this->customerId.'&month=2026-12');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $sheet->filter('[data-margin-unrebilled-item]'));
        self::assertStringContainsString('Cloud Host', $sheet->filter('[data-margin-unrebilled-item]')->text());
        self::assertSame('/cash-flow/planned-costs/'.$this->item, $sheet->filter('[data-margin-unrebilled-item-link]')->attr('href'));
        self::assertSame('/invoices/recurring/'.$this->templateId, $sheet->filter('[data-margin-unrebilled-template-link]')->attr('href'));

        // Paired by hand, it is rebilled: no badge, no list.
        $this->send('POST', '/api/v1/margin/rebills', ['invoice_line_id' => $this->invoiceLine, 'planned_item_id' => $this->item, 'occurrence_on' => '2026-12-05']);
        self::assertResponseStatusCodeSame(201);
        self::assertCount(0, $this->client->request('GET', '/margin?from=2026-12&to=2026-12')->filter('[data-margin-unrebilled]'));
        self::assertCount(0, $this->client->request('GET', '/margin/cell?row=client:'.$this->customerId.'&month=2026-12')->filter('[data-margin-unrebilled-item]'));
    }

    public function testAGuestHasNoMenuEntryAndCannotDecide(): void
    {
        $membership = $this->em()->getRepository(Membership::class)->findOneBy([]);
        self::assertInstanceOf(Membership::class, $membership);
        $membership->changeRole(Role::Guest, $this->em()->find(Party::class, $this->customerId));
        $this->em()->flush();

        foreach (['/margin/rebills/recurring-lines/'.$this->templateLine, '/margin/rebills/invoice-lines/'.$this->invoiceLine, '/margin/rebills/invoice-lines/'.$this->invoiceLine.'/unpair'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->client->request('POST', '/margin/rebills/recurring-lines/'.$this->templateLine, ['plannedItemId' => $this->item, '_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em()->getRepository(RebillLink::class)->count([]));
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $url, array $fields, Crawler $form): void
    {
        $token = $form->filter('form[id^="margin-rebill"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $url, $fields + ['_token' => $token]);
    }

    private function planned(string $name): string
    {
        $this->send('POST', '/api/v1/cash-flow/planned-items', ['direction' => 'out', 'name' => $name, 'category' => 'software', 'amount' => '12100.00', 'on' => '2026-12-05', 'repeat' => 'month', 'counterparty_id' => $this->supplier]);

        return $this->id();
    }

    private function id(): string
    {
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    private function lineId(): string
    {
        $id = $this->at('data', 'lines', 0, 'id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $path, array $body = []): void
    {
        $this->client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->writer, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: 'GET' === $method ? null : json_encode((object) $body, \JSON_THROW_ON_ERROR));
    }
}
