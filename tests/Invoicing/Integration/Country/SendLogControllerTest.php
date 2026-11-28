<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\MailSecrets;
use Foresi\Invoicing\Application\SendInvoice;
use Foresi\Invoicing\Application\SendRequest;
use Foresi\Invoicing\Application\TrashInvoice;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\FirmMailServer;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\MailEncryption;
use Foresi\Invoicing\Domain\SendPurpose;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
use Foresi\Tests\Support\Mail\RecordingMailGateway;
use Foresi\Tests\Support\Messenger\RunMessages;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The send log, the sends on the history of an invoice, their kept PDFs, the
 * warning of the form and the notices of the shell.
 */
final class SendLogControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private Series $series;
    private Party $customer;

    protected function setUp(): void
    {
        self::mockTime('2026-11-03 10:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        RecordingMailGateway::reset();
    }

    protected function tearDown(): void
    {
        RecordingMailGateway::reset();
        parent::tearDown();
    }

    public function testTheLogFiltersByViewAndPaginates(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        for ($i = 0; $i < 25; ++$i) {
            $this->sent($invoice);
        }
        $this->deliver();
        self::mockTime('2026-11-03 11:00:00');
        $failed = $this->issued();
        $this->sent($failed, ['refuse@example.com']);
        $this->deliver();

        $crawler = $this->client->request('GET', '/invoices/sent');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Sent e-mails');
        self::assertCount(25, $crawler->filter('tbody tr'));
        // Newest first: the failed one, with its error.
        self::assertStringContainsString('refuse@example.com', $crawler->filter('tbody tr')->first()->text());
        self::assertStringContainsString('SMTP 550', $crawler->filter('tbody tr')->first()->text());
        self::assertStringContainsString('Failed', $crawler->filter('tbody tr')->first()->text());
        self::assertSelectorTextContains('[data-table-footer]', '26');
        // One firm per instance: no firm column and no firm filter.
        self::assertSame(['Document', 'When', 'Type', 'Customer', 'Recipients', 'Status', 'Error', 'Actions'], $crawler->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text())));
        self::assertCount(0, $crawler->filter('[name="firm"]'));

        $crawler = $this->client->request('GET', '/invoices/sent?page=2');
        self::assertCount(1, $crawler->filter('tbody tr'));

        $crawler = $this->client->request('GET', '/invoices/sent?view=failed');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSame('Failed', trim($crawler->filter('nav[aria-label="Send states"] [aria-current="page"]')->text()));
        $crawler = $this->client->request('GET', '/invoices/sent?view=sent');
        self::assertCount(25, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('[data-table-footer]', '25');
        $crawler = $this->client->request('GET', '/invoices/sent?view=queued');
        self::assertCount(0, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('main', 'No e-mail matches');
        // A view from nowhere, and the firm of the old filter, are ignored, not an error.
        $crawler = $this->client->request('GET', '/invoices/sent?view=bounced&firm=nonsense');
        self::assertResponseIsSuccessful();
        self::assertCount(25, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('[data-table-footer]', '26');
    }

    public function testTheTabsAreTheShippedViewsAndTheOldStatusAddressesAlias(): void
    {
        // Saved views, D12: the tabs the log had, after All (D7), none with a count (D9).
        $this->signedIn();
        $invoice = $this->issued();
        $this->sent($invoice);
        $this->deliver();
        $this->sent($invoice, ['refuse@example.com']);
        $this->deliver();

        $crawler = $this->client->request('GET', '/invoices/sent');
        self::assertSame(['All', 'Sent', 'Waiting', 'Failed'], $this->viewTabs($crawler));
        self::assertSame(['/invoices/sent', '/invoices/sent?view=sent', '/invoices/sent?view=queued', '/invoices/sent?view=failed'], $crawler->filter('nav[aria-label="Send states"] a')->each(static fn (Crawler $tab): ?string => $tab->attr('href')));
        self::assertCount(1, $crawler->filter('[data-filter-sheet-form]'));
        self::assertCount(1, $crawler->filter('[data-column-picker-form]'));

        foreach (['?status=failed' => ['Failed', 1], '?status=sent' => ['Sent', 1], '?status=queued' => ['Waiting', 0], '?status=all' => ['All', 2], '?status=bounced' => ['All', 2]] as $address => [$tab, $rows]) {
            $crawler = $this->client->request('GET', '/invoices/sent'.$address);
            self::assertResponseIsSuccessful();
            self::assertSame($tab, trim($crawler->filter('nav[aria-label="Send states"] [aria-current="page"]')->text()), $address);
            self::assertCount($rows, $crawler->filter('tbody tr'), $address);
        }
    }

    public function testTheBuilderNarrowsTheViewAndItsChangesAreSavedAsANewView(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->sent($invoice);
        $this->deliver();
        self::mockTime('2026-11-25 10:00:00');
        $this->reminded($invoice, 1);

        $this->client->request('GET', '/invoices/sent?'.http_build_query(['view' => 'queued', 'purpose' => 'invoice', 'conditions' => '1', 'c' => [['field' => 'purpose', 'op' => 'is', 'values' => ['reminder']]]]));
        self::assertResponseStatusCodeSame(303);
        // The old select is in the rows by then: the address keeps the view and the rows alone.
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/invoices/sent?view=queued&f=', $location);
        self::assertStringNotContainsString('purpose=', $location);
        $crawler = $this->client->followRedirect();
        self::assertSame(['Reminder 1'], $crawler->filter('tbody tr [data-icon-cell]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertSame('Waiting, unsaved changes', $this->viewTabs($crawler)[2]);

        $this->client->request('GET', '/invoices/sent?'.http_build_query(['conditions' => '1', 'c' => [['field' => 'purpose', 'op' => 'is', 'values' => ['reminder']]]]));
        $crawler = $this->client->followRedirect();
        self::assertSame(['Reminder 1'], $crawler->filter('tbody tr [data-icon-cell]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertSame('All, unsaved changes', $this->viewTabs($crawler)[0]);
        $this->client->submit($crawler->filter('[data-view-save-as-form]')->form(['name' => 'Reminders']));
        $crawler = $this->client->followRedirect();
        self::assertSame(['Reminder 1'], $crawler->filter('tbody tr [data-icon-cell]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertContains('Reminders', $this->viewTabs($crawler));
    }

    public function testTheColumnsSheetHidesAColumn(): void
    {
        $this->signedIn();
        $this->sent($this->issued());
        $this->deliver();

        $crawler = $this->client->request('GET', '/invoices/sent?cols=number,status');

        self::assertSame(['Document', 'Status', 'Actions'], $crawler->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text())));
        self::assertCount(1, $crawler->filter('tbody tr'));
    }

    public function testAFailureReadsInTheLanguageOfTheUser(): void
    {
        $this->signedIn(locale: 'cs');
        $invoice = $this->issued();
        $send = $this->sent($invoice, ['refuse@example.com']);
        $this->deliver();

        $crawler = $this->client->request('GET', '/invoices/sent');
        self::assertStringContainsString('Poštovní server zprávu odmítl (SMTP 550).', $crawler->filter('tbody tr')->text());
        $stored = $this->connection()->fetchAssociative('SELECT last_error_code, last_error_smtp FROM invoice_send WHERE id = ?', [$send]);
        self::assertIsArray($stored);
        self::assertSame('message_refused', $stored['last_error_code']);
        self::assertEquals(550, $stored['last_error_smtp']);
        $crawler = $this->client->request('GET', '/invoices/'.$invoice->id().'/history');
        self::assertStringContainsString('Poštovní server zprávu odmítl (SMTP 550).', $crawler->filter('[data-send-entry]')->text());

        // A row from before codes shows the English text it kept.
        $this->connection()->executeStatement('UPDATE invoice_send SET last_error_code = NULL, last_error_smtp = NULL WHERE id = ?', [$send]);
        $crawler = $this->client->request('GET', '/invoices/sent');
        self::assertStringContainsString('SMTP 550: the mail server refused the message.', $crawler->filter('tbody tr')->text());
    }

    public function testHistoryShowsEverySendWithKeptPdf(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $first = $this->sent($invoice, ['billing@example.com'], ['accounts@example.com'], 'Invoice 20260001', "Dear customer,\n{link}");
        $this->deliver();
        $this->opened($first);
        self::mockTime('2026-11-04 08:00:00');
        $second = $this->sent($invoice, ['refuse@example.com']);
        $this->deliver();

        $crawler = $this->client->request('GET', '/invoices/'.$invoice->id().'/history');

        self::assertResponseIsSuccessful();
        $sends = $crawler->filter('[data-send-entry]');
        self::assertCount(2, $sends);
        // In time with the changes: created first, then the two sends.
        // Created, the first send and the delivery it made, then the second send.
        self::assertSame(['change', 'send', 'change', 'send'], $crawler->filter('[data-invoice-history] > li')->each(static fn (Crawler $item): string => null === $item->attr('data-send-entry') ? 'change' : 'send'));
        $one = $sends->eq(0);
        self::assertStringContainsString('Example Company sent an e-mail to billing@example.com, accounts@example.com', $one->text());
        self::assertStringContainsString('Invoice 20260001', $one->text());
        self::assertStringContainsString('Dear customer,', $one->text());
        self::assertStringContainsString('Read 2026-11-03 13:30', $one->text());
        $two = $sends->eq(1);
        self::assertStringContainsString('Failed', $two->text());
        self::assertStringContainsString('SMTP 550', $two->text());
        $again = $two->filter('a')->reduce(static fn (Crawler $link): bool => 'Send again' === trim($link->text()));
        self::assertSame('/invoices/'.$invoice->id().'/send', $again->attr('href'));
        // Opens over the history, which it then shows again with the message.
        self::assertNotNull($again->attr('data-dialog'));
        self::assertSame('dialog', $again->attr('aria-haspopup'));

        $pdf = $one->filter('a')->reduce(static fn (Crawler $link): bool => str_contains($link->text(), 'PDF'))->attr('href');
        self::assertSame('/invoices/'.$invoice->id().'/sends/'.$first.'/pdf', $pdf);
        $this->client->request('GET', $pdf);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        $bytes = $this->client->getInternalResponse()->getContent();
        self::assertSame($this->storedHash($first), hash('sha256', $bytes));
        // A send of another invoice under this one is not found.
        $other = $this->issued();
        $this->client->request('GET', '/invoices/'.$other->id().'/sends/'.$second.'/pdf');
        self::assertResponseStatusCodeSame(404);
    }

    public function testChangedAfterSendingWarns(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->sent($invoice);
        $this->deliver();
        self::mockTime('2026-11-03 10:30:00');
        $this->savedWithDescription($invoice, 'Changed');

        $this->client->request('GET', '/invoices/'.$invoice->id().'/edit');

        self::assertSelectorTextContains('[data-changed-after-send]', 'This invoice changed after it was sent on 2026-11-03 11:00.');
    }

    public function testAChangeInTheSecondOfTheSendStillWarns(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->sent($invoice);
        $this->deliver();
        $this->savedWithDescription($invoice, 'Changed');

        $this->client->request('GET', '/invoices/'.$invoice->id().'/edit');

        self::assertSelectorExists('[data-changed-after-send]');
    }

    public function testNoWarningWhenUnchanged(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->sent($invoice);
        // Delivery marks the invoice delivered, which is no change of what was sent.
        $this->deliver();

        $this->client->request('GET', '/invoices/'.$invoice->id().'/edit');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-changed-after-send]');
    }

    public function testAFailedSendRaisesANoticeUntilALaterSuccess(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->sent($invoice, ['refuse@example.com']);
        $this->deliver();

        $this->client->request('GET', '/contacts');
        self::assertSelectorTextContains('[data-shell-notices]', 'An invoice could not be sent.');

        self::mockTime('2026-11-03 10:05:00');
        $this->sent($invoice);
        $this->deliver();
        $this->client->request('GET', '/contacts');
        self::assertSelectorNotExists('[data-shell-notices]');
    }

    public function testAFailedSendRaisesANoticeUntilTrash(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->sent($invoice, ['refuse@example.com']);
        $this->deliver();
        self::getContainer()->get(TrashInvoice::class)->trash($invoice->id(), 'Wrong customer');

        $this->client->request('GET', '/contacts');

        self::assertSelectorNotExists('[data-shell-notices]');
    }

    public function testAStuckQueueRaisesANotice(): void
    {
        $this->signedIn();
        $this->sent($this->issued());

        self::mockTime('2026-11-03 10:14:00');
        $this->client->request('GET', '/contacts');
        self::assertSelectorNotExists('[data-shell-notices]');

        self::mockTime('2026-11-03 10:16:00');
        $this->client->request('GET', '/contacts');
        self::assertSelectorTextContains('[data-shell-notices]', 'waiting to be sent for more than 15 minutes');
    }

    public function testARetryingSendShowsItsOwnNoteNotTheStuckOne(): void
    {
        $this->signedIn();
        $send = $this->sent($this->issued());
        $this->connection()->executeStatement("UPDATE invoice_send SET attempts = 1, last_error = 'SMTP 451: the mail server asks to try again later.', last_error_code = 'try_later', last_error_smtp = 451 WHERE id = ?", [$send]);

        self::mockTime('2026-11-03 10:30:00');
        $crawler = $this->client->request('GET', '/contacts');

        $notices = $crawler->filter('[data-shell-notices]')->text();
        self::assertStringNotContainsString('more than 15 minutes', $notices);
        self::assertStringContainsString('An e-mail is being tried again: the mail server has not taken it yet.', $notices);
        self::assertSame('/invoices/sent?view=queued', $crawler->filter('[data-shell-notices] a')->attr('href'));
    }

    public function testTheLogShowsWhenASendWasRead(): void
    {
        $this->signedIn();
        $send = $this->sent($this->issued());
        $this->deliver();
        $this->opened($send);

        $crawler = $this->client->request('GET', '/invoices/sent');

        // In the badge itself, not only for a screen reader.
        self::assertSame('Read 2026-11-03 13:30', $crawler->filter('tbody tr td span.rounded-sm')->text());
        self::assertCount(0, $crawler->filter('tbody tr td span.rounded-sm .sr-only'));
    }

    public function testTheNoticeLinksToTheLog(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->sent($invoice, ['refuse@example.com']);
        $this->deliver();

        $crawler = $this->client->request('GET', '/contacts');

        self::assertSame('/invoices/sent?view=failed', $crawler->filter('[data-shell-notices] a')->first()->attr('href'));
        $this->client->click($crawler->filter('[data-shell-notices] a')->first()->link());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->client->getCrawler()->filter('tbody tr'));
        // The log itself does not repeat the notice that led to it.
        self::assertSelectorNotExists('[data-shell-notices]');
    }

    public function testTheLogFiltersByPurpose(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->sent($invoice);
        self::mockTime('2026-11-25 10:00:00');
        $this->reminded($invoice, 1);

        $crawler = $this->client->request('GET', '/invoices/sent');

        self::assertContains('Type', $crawler->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text())));
        self::assertSame(['Reminder 1', 'Invoice'], $crawler->filter('tbody tr [data-icon-cell]')->each(static fn (Crawler $cell): string => trim($cell->text())));

        // The old select is a condition of the filter sheet now: its address still narrows (saved views D11, D12).
        $crawler = $this->client->request('GET', '/invoices/sent?purpose=reminder');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSame('All, unsaved changes', $this->viewTabs($crawler)[0]);
        self::assertStringContainsString('Reminder', $crawler->filter('[data-filter-condition]')->first()->text());
        // The tabs open each view as it is saved: the purpose belongs to the view it was added on.
        self::assertSame('/invoices/sent?view=queued', $crawler->filter('nav[aria-label="Send states"] a')->eq(2)->attr('href'));
        $crawler = $this->client->request('GET', '/invoices/sent?status=sent&purpose=reminder');
        self::assertCount(0, $crawler->filter('tbody tr'), 'the reminder waits, it was not sent');
        // A reminder row offers the next reminder, not the invoice again.
        $crawler = $this->client->request('GET', '/invoices/sent?purpose=reminder');
        self::assertStringEndsWith('/invoices/'.$invoice->id().'/remind', (string) $crawler->filter('tbody tr a[data-dialog]:not([data-row-link])')->first()->attr('href'));

        $crawler = $this->client->request('GET', '/invoices/sent?purpose=invoice');
        self::assertSame(['Invoice'], $crawler->filter('tbody tr [data-icon-cell]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        $crawler = $this->client->request('GET', '/invoices/sent?purpose=nonsense');
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertSame('All', $this->viewTabs($crawler)[0]);
    }

    public function testTheBuilderFiltersByTheDocumentAndTheTime(): void
    {
        $this->signedIn();
        $first = $this->issued();
        $this->sent($first);
        self::mockTime('2026-11-10 09:00:00');
        $other = $this->newCustomer('Other Buyer s.r.o.');
        $second = $this->issued(customer: $other);
        $this->sent($second);

        $shown = function (array $conditions): array {
            $this->client->request('GET', '/invoices/sent?'.http_build_query(['conditions' => '1', 'c' => $conditions]));

            return $this->client->followRedirect()->filter('tbody tr td:first-child')->each(static fn (Crawler $cell): string => substr(trim($cell->text()), 0, 8));
        };

        // The number and the customer are the document's, read in a subquery of it (D4).
        self::assertSame([$second->number()], $shown([['field' => 'number', 'op' => 'is', 'value' => $second->number()]]));
        self::assertSame([$second->number()], $shown([['field' => 'customer', 'op' => 'contains', 'value' => 'other buyer']]));
        self::assertSame([$first->number()], $shown([['field' => 'customer', 'op' => 'does_not_contain', 'value' => 'other buyer']]));
        self::assertSame([$first->number()], $shown([['field' => 'when', 'op' => 'before', 'from' => ['rule' => FilterRows::DAY, 'day' => '2026-11-05']]]));
    }

    public function testHistoryNamesTheReminderStep(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->sent($invoice);
        $this->deliver();
        self::mockTime('2026-11-25 10:00:00');
        $this->reminded($invoice, 1);
        $this->deliver();
        $this->opened($this->reminded($invoice, 2, ['refuse@example.com']));
        $this->deliver();

        $crawler = $this->client->request('GET', '/invoices/'.$invoice->id().'/history');

        $sends = $crawler->filter('[data-send-entry]');
        self::assertCount(3, $sends);
        self::assertSame(['send', 'bell-ring', 'bell-ring'], $sends->each(static fn (Crawler $entry): string => (string) $entry->attr('data-send-entry')));
        self::assertStringContainsString('sent reminder 1 to billing@example.com', $sends->eq(1)->text());
        self::assertStringContainsString('Sent', $sends->eq(1)->text());
        $failed = $sends->eq(2);
        self::assertStringContainsString('sent reminder 2 to refuse@example.com', $failed->text());
        self::assertStringContainsString('Failed', $failed->text());
        self::assertStringContainsString('SMTP 550', $failed->text());
        // The newest is a reminder, so it offers the next reminder rather than the invoice again.
        $again = $failed->filter('a[data-dialog]');
        self::assertSame('Send another reminder', trim($again->text()));
        self::assertSame('/invoices/'.$invoice->id().'/remind', $again->attr('href'));
    }

    /**
     * @param list<string> $to
     */
    private function reminded(Invoice $invoice, int $step, array $to = ['billing@example.com']): string
    {
        return self::getContainer()->get(SendInvoice::class)->send(new SendRequest($invoice->id(), $to, [], false, 'Reminder', '{link}', SendPurpose::Reminder, $step))->id();
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     */
    private function sent(Invoice $invoice, array $to = ['billing@example.com'], array $cc = [], string $subject = 'Invoice', string $body = '{link}'): string
    {
        return self::getContainer()->get(SendInvoice::class)->send(new SendRequest($invoice->id(), $to, $cc, false, $subject, $body))->id();
    }

    private function savedWithDescription(Invoice $invoice, string $description): void
    {
        $values = $this->client->request('GET', '/invoices/'.$invoice->id().'/edit')->filter('form#invoice-form')->form()->getPhpValues();
        $values['description'] = $description;
        $this->client->request('POST', '/invoices/'.$invoice->id().'/edit', array_merge($values, ['_action' => 'save']));
        self::assertResponseRedirects('/invoices');
    }

    private function deliver(): void
    {
        $mail = self::getContainer()->get('messenger.transport.mail');
        self::assertInstanceOf(InMemoryTransport::class, $mail);
        try {
            new RunMessages($mail, self::getContainer()->get(MessageBusInterface::class))->run();
        } catch (HandlerFailedException) {
            // A refused message fails for good, and the worker reports it: the send is stored as failed.
        }
    }

    private function opened(string $sendId): void
    {
        $this->connection()->executeStatement("UPDATE invoice_send SET first_read_at = '2026-11-03 12:30:00', read_count = 1 WHERE id = ?", [$sendId]);
    }

    private function storedHash(string $sendId): string
    {
        $hash = $this->connection()->fetchOne('SELECT pdf_sha256 FROM invoice_send WHERE id = ?', [$sendId]);
        self::assertIsString($hash);

        return $hash;
    }

    /**
     * @return list<string>
     */
    private function viewTabs(Crawler $crawler): array
    {
        return $crawler->filter('nav[aria-label="Send states"] a')->each(static fn (Crawler $tab): string => trim($tab->text()));
    }

    private function issued(?Party $customer = null): Invoice
    {
        $day = new \DateTimeImmutable('2026-11-01');
        $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('1000.00', Currency::of('CZK')), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

        return self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput($this->series->id(), null, ($customer ?? $this->customer)->id(), null, $day, $day, new \DateTimeImmutable('2026-11-15'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Consulting', '', '', DocumentLanguage::Czech, [$line]));
    }

    private function signedIn(Role $role = Role::Owner, string $locale = 'en'): void
    {
        $this->browser->seed($locale, $role);
        $this->series = $this->firmWithServer('Example Firm s.r.o.', '12345695');
        $this->customer = $this->newCustomer('Example Customer a.s.');
        $this->browser->signIn();
    }

    private function firmWithServer(string $name, string $registrationNumber): Series
    {
        $em = $this->em();
        $em->persist(new LegalEntity($name, new RegistrationNumber('CZ', $registrationNumber), VatId::fromString('CZ'.$registrationNumber), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1'));
        FirmAccounts::add($em, self::IBAN);
        $series = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $series->markDefault();
        $em->persist($series);
        $em->persist(FirmMailServer::define('smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', self::getContainer()->get(MailSecrets::class)->seal('correct-horse-battery'), 'invoices@example.com', $name));
        $em->flush();

        return $series;
    }

    private function newCustomer(string $name): Party
    {
        $em = $this->em();
        $customer = new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $em->persist($customer);
        $em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $em->persist(new PartyEmail($customer, 'billing@example.com', EmailPurpose::Invoices));
        $em->flush();

        return $customer;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function em(): EntityManagerInterface
    {
        return $this->browser->em();
    }
}
