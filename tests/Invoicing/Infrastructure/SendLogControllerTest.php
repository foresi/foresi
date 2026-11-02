<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Identity\Domain\Role;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\SendInvoice;
use App\Invoicing\Application\SendRequest;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\MailEncryption;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Tests\Import\Support\RunMessages;
use App\Tests\Party\Support\FirmAccounts;
use App\Tests\Support\Mail\RecordingMailGateway;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
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
    private TenantContext $context;
    private TenantId $tenant;
    private LegalEntity $firm;
    private Series $series;
    private Party $customer;

    protected function setUp(): void
    {
        self::mockTime('2026-11-03 10:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), $this->context);
        RecordingMailGateway::reset();
    }

    protected function tearDown(): void
    {
        RecordingMailGateway::reset();
        parent::tearDown();
    }

    public function testTheLogFiltersByFirmAndStatusAndPaginates(): void
    {
        $this->signedIn();
        $other = $this->scoped(fn (): array => $this->firmWithServer('Example Other s.r.o.', '12345687'));
        $invoice = $this->issued();
        for ($i = 0; $i < 25; ++$i) {
            $this->sent($invoice);
        }
        $this->deliver();
        self::mockTime('2026-11-03 11:00:00');
        $failed = $this->issued(firm: $other);
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

        $crawler = $this->client->request('GET', '/invoices/sent?page=2');
        self::assertCount(1, $crawler->filter('tbody tr'));

        $crawler = $this->client->request('GET', '/invoices/sent?firm='.$other[0]->id());
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Example Other s.r.o.', $crawler->filter('tbody tr')->text());

        $crawler = $this->client->request('GET', '/invoices/sent?status=failed');
        self::assertCount(1, $crawler->filter('tbody tr'));
        $crawler = $this->client->request('GET', '/invoices/sent?status=sent&firm='.$other[0]->id());
        self::assertCount(0, $crawler->filter('tbody tr'));
        // A value from nowhere is ignored, not an error.
        $crawler = $this->client->request('GET', '/invoices/sent?status=bounced&firm=nonsense');
        self::assertResponseIsSuccessful();
        self::assertCount(25, $crawler->filter('tbody tr'));
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

    public function testTheLogIsTenantIsolated(): void
    {
        $this->signedIn();
        $this->foreignSend();

        $crawler = $this->client->request('GET', '/invoices/sent');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('tbody tr'));
        self::assertStringNotContainsString('Foreign', (string) $this->client->getResponse()->getContent());
    }

    public function testTheLogIsForStaffAndInTheSidebar(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/invoices/sent');

        $current = $crawler->filter('nav a[aria-current="page"]');
        self::assertSame(['Sent e-mails'], $current->each(static fn (Crawler $link): string => trim($link->text())));

        $this->client->request('GET', '/invoices');
        self::assertSame(['Invoices'], $this->client->getCrawler()->filter('nav a[aria-current="page"]')->each(static fn (Crawler $link): string => trim($link->text())));
    }

    public function testASubcontractorGets403(): void
    {
        $this->signedIn(Role::Subcontractor);

        $this->client->request('GET', '/invoices/sent');

        self::assertResponseStatusCodeSame(403);
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

    public function testTheKeptPdfOfAnotherTenantIs404(): void
    {
        $this->signedIn();
        [$invoice, $send] = $this->foreignSend();

        $this->client->request('GET', '/invoices/'.$invoice.'/sends/'.$send.'/pdf');

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
        $this->scoped(static fn () => self::getContainer()->get(TrashInvoice::class)->trash($invoice->id(), 'Wrong customer'));

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
        self::assertSame('/invoices/sent?status=queued', $crawler->filter('[data-shell-notices] a')->attr('href'));
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

        self::assertSame('/invoices/sent?status=failed', $crawler->filter('[data-shell-notices] a')->first()->attr('href'));
        $this->client->click($crawler->filter('[data-shell-notices] a')->first()->link());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->client->getCrawler()->filter('tbody tr'));
        // The log itself does not repeat the notice that led to it.
        self::assertSelectorNotExists('[data-shell-notices]');
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     */
    private function sent(Invoice $invoice, array $to = ['billing@example.com'], array $cc = [], string $subject = 'Invoice', string $body = '{link}'): string
    {
        return $this->scoped(static fn (): string => self::getContainer()->get(SendInvoice::class)->send(new SendRequest($invoice->id(), $to, $cc, false, $subject, $body))->id());
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
        $this->scoped(static function (): void {
            $mail = self::getContainer()->get('messenger.transport.mail');
            self::assertInstanceOf(InMemoryTransport::class, $mail);
            try {
                new RunMessages($mail, self::getContainer()->get(MessageBusInterface::class))->run();
            } catch (HandlerFailedException) {
                // A refused message fails for good, and the worker reports it: the send is stored as failed.
            }
        });
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
     * @param array{LegalEntity, Series}|null $firm
     */
    private function issued(?array $firm = null): Invoice
    {
        return $this->scoped(function () use ($firm): Invoice {
            [$issuer, $series] = $firm ?? [$this->firm, $this->series];
            $day = new \DateTimeImmutable('2026-11-01');
            $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

            return self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput($issuer->id(), $series->id(), null, $this->customer->id(), null, $day, $day, new \DateTimeImmutable('2026-11-15'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Consulting', '', '', DocumentLanguage::Czech, [$line]));
        });
    }

    /**
     * @return array{string, string} the invoice and the send, in a tenant of their own
     */
    private function foreignSend(): array
    {
        $tenant = $this->browser->fixtures->tenant('Foreign Tenant');
        $this->context->switchTo($tenant);
        try {
            [$firm, $series] = $this->firmWithServer('Foreign Firm s.r.o.', '12345679');
            $customer = $this->newCustomer('Foreign Customer a.s.');
            $day = new \DateTimeImmutable('2026-11-01');
            $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('100.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
            $invoice = self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput($firm->id(), $series->id(), null, $customer->id(), null, $day, $day, new \DateTimeImmutable('2026-12-01'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Foreign', '', '', DocumentLanguage::Czech, [$line]));
            $send = self::getContainer()->get(SendInvoice::class)->send(new SendRequest($invoice->id(), ['foreign@example.com'], [], false, 'Foreign invoice', '{link}'));

            return [$invoice->id(), $send->id()];
        } finally {
            $this->context->reset();
        }
    }

    private function signedIn(Role $role = Role::Owner, string $locale = 'en'): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A'], $locale, $role);
        $this->scoped(function (): void {
            [$this->firm, $this->series] = $this->firmWithServer('Example Firm s.r.o.', '12345695');
            $this->customer = $this->newCustomer('Example Customer a.s.');
        });
        $this->browser->signIn();
    }

    /**
     * @return array{LegalEntity, Series}
     */
    private function firmWithServer(string $name, string $registrationNumber): array
    {
        $em = $this->em();
        $firm = new LegalEntity($name, new RegistrationNumber('CZ', $registrationNumber), VatId::fromString('CZ'.$registrationNumber), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $em->persist($firm);
        FirmAccounts::add($em, $firm, self::IBAN);
        $series = new Series($firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $series->markDefault();
        $em->persist($series);
        $em->persist(FirmMailServer::define($firm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', self::getContainer()->get(MailSecrets::class)->seal('correct-horse-battery'), 'invoices@example.com', $name));
        $em->flush();

        return [$firm, $series];
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

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function scoped(callable $work): mixed
    {
        $this->context->switchTo($this->tenant);
        try {
            return $work();
        } finally {
            $this->context->reset();
        }
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
